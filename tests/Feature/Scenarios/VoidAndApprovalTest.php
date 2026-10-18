<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PointEntryType;
use App\Enums\StockMovementType;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * Skenario Phase 3: void & rantai approval PIN di outlet JKT01 (pajak 11%, service 5%, pembulatan Rp100,
 * batas diskon kasir 10%, supervisor 25%, owner/manager tanpa batas). Semua PIN factory = 123456.
 * Shift kasir Budi terbuka dengan kas awal 200.000. Croissant 24.000 stok 10.
 * Program member aktif di outlet: Rp10.000 subtotal = 1 poin.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00'); // 10.00 WIB
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create(['name' => 'Maya']);

    TenantContext::run($this->pos->tenantId, function (): void {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $this->member = Member::factory()->create(['name' => 'Rina']);
    });
});

afterEach(fn () => Carbon::setTestNow());

function vatApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Checkout kasir: $qty Croissant, satu pembayaran. */
function vatCheckout(object $test, int $qty, string $category, int $amount, array $extra = []): TestResponse
{
    $payment = ['id' => uuid(), 'payment_method_id' => $test->pos->methods[$category]->id, 'amount' => $amount];

    return vatApi($test, 'POST', '/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [$category === 'cash' ? [...$payment, 'tendered' => $amount] : $payment],
        ...$extra,
    ]);
}

/** Body diskon persen dengan (opsional) PIN approver. */
function vatDiscount(int $percent, ?User $approver = null): array
{
    return [
        'discount' => ['type' => 'percent', 'value' => $percent],
        ...($approver !== null ? ['approver_user_id' => $approver->id, 'approver_pin' => '123456'] : []),
    ];
}

function vatReport(object $test, string $path): TestResponse
{
    return vatApi($test, 'GET', '/reports/'.$path, token: userToken($test->owner));
}

describe('void order lunas', function () {
    it('kasir void: tanpa PIN ditolak, PIN sendiri ditolak, PIN supervisor berhasil; stok, poin, laporan, kas shift ikut', function () {
        $pos = $this->pos;

        // ---- Langkah 1: penjualan pagi ----
        // A (member, tunai): 3 Croissant = 72.000; S 3.600; T 11% × 75.600 = 8.316; 83.916 → 83.900; poin ⌊72.000 / 10.000⌋ = 7
        $voidId = vatCheckout($this, 3, 'cash', 100000, ['member_id' => $this->member->id])
            ->assertCreated()
            ->assertJsonPath('data.grand_total', '83900.00')
            ->assertJsonPath('data.change_total', '16100.00')
            ->json('data.id');
        // B (QRIS): 1 Croissant = 24.000; S 1.200; T 11% × 25.200 = 2.772; 27.972 → 28.000
        vatCheckout($this, 1, 'qris', 28000)->assertCreated()->assertJsonPath('data.grand_total', '28000.00');

        expect(stockOf($pos->croissant)->stock_qty)->toBe(6) // 10 − 3 − 1
            ->and($this->member->refresh()->points_balance)->toBe(7)
            ->and($this->member->visit_count)->toBe(1);

        // ---- Langkah 2: kasir mencoba void tanpa PIN → APPROVAL_REQUIRED ----
        assertApiError(vatApi($this, 'POST', "/orders/{$voidId}/void", ['reason' => 'Salah input']), 'APPROVAL_REQUIRED', 403);

        // ---- Langkah 3: kasir memasukkan PIN-nya sendiri → SELF_APPROVAL_NOT_ALLOWED ----
        assertApiError(vatApi($this, 'POST', "/orders/{$voidId}/void", [
            'reason' => 'Salah input', 'approver_user_id' => $pos->cashier->id, 'approver_pin' => '123456',
        ]), 'SELF_APPROVAL_NOT_ALLOWED', 403);

        // Dua penolakan tidak mengubah apa pun
        expect(Order::allTenants()->find($voidId)?->status)->toBe(OrderStatus::Completed)
            ->and(stockOf($pos->croissant)->stock_qty)->toBe(6);

        // ---- Langkah 4: PIN supervisor Sari → void berhasil ----
        vatApi($this, 'POST', "/orders/{$voidId}/void", [
            'reason' => 'Salah input', 'approver_user_id' => $pos->supervisor->id, 'approver_pin' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.voided_by', $pos->cashier->id)
            ->assertJsonPath('data.void_approved_by', $pos->supervisor->id)
            ->assertJsonPath('data.payments.0.status', 'voided');

        // Stok kembali 3 → 9; poin member ditarik 7 → 0; kunjungan kembali 0
        $member = $this->member->refresh();
        expect(stockOf($pos->croissant)->stock_qty)->toBe(9)
            ->and(StockMovement::allTenants()->where('type', StockMovementType::VoidReturn)->sole()->qty_change)->toBe(3)
            ->and($member->points_balance)->toBe(0)
            ->and($member->visit_count)->toBe(0)
            ->and($member->total_spent)->toBe('0.00')
            ->and(MemberPointEntry::allTenants()->where('type', PointEntryType::VoidReversal)->sole()->points)->toBe(-7)
            ->and(Payment::allTenants()->where('order_id', $voidId)->sole()->status)->toBe(PaymentStatus::Voided);

        // ---- Langkah 5: rekap shift tanpa order void ----
        // Sisa 1 order QRIS 28.000; tunai 0 → kas seharusnya = kas awal 200.000
        vatApi($this, 'GET', "/shifts/{$pos->shift->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.summary.order_count', 1)
            ->assertJsonPath('data.summary.sales_total', '28000.00')
            ->assertJsonPath('data.summary.cash_sales', '0.00')
            ->assertJsonPath('data.summary.expected_cash', '200000.00');

        // ---- Langkah 6: laporan owner: void dipisah dari omzet ----
        vatReport($this, 'summary')
            ->assertOk()
            ->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.revenue', '28000.00')
            ->assertJsonPath('data.items_sold', 1)
            ->assertJsonPath('data.void_count', 1)
            ->assertJsonPath('data.void_total', '83900.00');
        vatReport($this, 'payment-methods')
            ->assertJsonCount(1, 'data.payment_methods')
            ->assertJsonPath('data.payment_methods.0.category', 'qris')
            ->assertJsonPath('data.payment_methods.0.amount', '28000.00');
        vatReport($this, 'products')
            ->assertJsonPath('data.products.0.qty', 1)
            ->assertJsonPath('data.products.0.revenue', '24000.00');
        vatReport($this, 'audit')
            ->assertJsonPath('data.void_count', 1)
            ->assertJsonPath('data.voids.0.voided_by', 'Budi')
            ->assertJsonPath('data.voids.0.reason', 'Salah input');

        // ---- Langkah 7: tutup shift dengan kas awal saja → selisih 0 ----
        vatApi($this, 'POST', "/shifts/{$pos->shift->id}/close", ['actual_cash' => 200000])
            ->assertOk()
            ->assertJsonPath('data.shift.expected_cash', '200000.00')
            ->assertJsonPath('data.shift.difference', '0.00');
    });

    it('void dikirim ulang setelah berhasil tidak mengembalikan stok/poin dua kali', function () {
        $id = vatCheckout($this, 3, 'cash', 100000, ['member_id' => $this->member->id])->assertCreated()->json('data.id');
        $body = ['reason' => 'Batal', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456'];

        vatApi($this, 'POST', "/orders/{$id}/void", $body)->assertOk();
        vatApi($this, 'POST', "/orders/{$id}/void", $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

        expect(stockOf($this->pos->croissant)->stock_qty)->toBe(10)
            ->and($this->member->refresh()->points_balance)->toBe(0)
            ->and(MemberPointEntry::allTenants()->where('type', PointEntryType::VoidReversal)->count())->toBe(1);
    });

    it('supervisor tenant lain tidak bisa meng-approve void (dianggap PIN salah)', function () {
        $id = vatCheckout($this, 1, 'cash', 28000)->assertCreated()->json('data.id');
        $other = posSetup();

        assertApiError(vatApi($this, 'POST', "/orders/{$id}/void", [
            'reason' => 'x', 'approver_user_id' => $other->supervisor->id, 'approver_pin' => '123456',
        ]), 'INVALID_PIN', 401);
        expect(Order::allTenants()->find($id)?->status)->toBe(OrderStatus::Completed);
    });
});

describe('rantai approval diskon', function () {
    it('kasir 20% → DISCOUNT_OVER_LIMIT; PIN supervisor → disetujui; 50% PIN supervisor ditolak; PIN manager → disetujui', function () {
        $pos = $this->pos;

        // 2 Croissant = 48.000 (harga kotor, dasar persen batas diskon)

        // ---- Langkah 1: kasir 20% tanpa PIN (batas kasir 10%) ----
        $response = vatCheckout($this, 2, 'cash', 100000, vatDiscount(20));
        assertApiError($response, 'DISCOUNT_OVER_LIMIT', 422);
        $response->assertJsonPath('error.details.limit_percent', '10')
            ->assertJsonPath('error.details.discount_percent', '20.00');

        // ---- Langkah 2: 20% dengan PIN supervisor (batas 25%) ----
        // Diskon 9.600 → dasar 38.400; S 1.920; T 11% × 40.320 = 4.435,20; 44.755,20 → 44.800
        vatCheckout($this, 2, 'cash', 100000, vatDiscount(20, $pos->supervisor))
            ->assertCreated()
            ->assertJsonPath('data.order_discount', '9600.00')
            ->assertJsonPath('data.approved_by', $pos->supervisor->id)
            ->assertJsonPath('data.grand_total', '44800.00');

        // ---- Langkah 3: kasir 50% dengan PIN supervisor → supervisor tidak berwenang di atas 25% ----
        assertApiError(vatCheckout($this, 2, 'cash', 100000, vatDiscount(50, $pos->supervisor)), 'FORBIDDEN', 403);

        // ---- Langkah 4: 50% dengan PIN manager Maya (tanpa batas) ----
        // Diskon 24.000 → dasar 24.000; S 1.200; T 11% × 25.200 = 2.772; 27.972 → 28.000
        vatCheckout($this, 2, 'cash', 100000, vatDiscount(50, $this->manager))
            ->assertCreated()
            ->assertJsonPath('data.order_discount', '24000.00')
            ->assertJsonPath('data.approved_by', $this->manager->id)
            ->assertJsonPath('data.grand_total', '28000.00');

        // Hanya 2 order tersimpan; stok hanya dipotong yang berhasil: 10 − 2 − 2 = 6
        expect(Order::allTenants()->count())->toBe(2)
            ->and(stockOf($pos->croissant)->stock_qty)->toBe(6);

        // Kas seharusnya = 200.000 + 44.800 + 28.000 = 272.800
        vatApi($this, 'GET', "/shifts/{$pos->shift->id}/summary")
            ->assertJsonPath('data.summary.order_count', 2)
            ->assertJsonPath('data.summary.expected_cash', '272800.00');

        // Laporan: omzet 72.800, diskon 9.600 + 24.000 = 33.600; audit mencatat 2 diskon ber-approval
        vatReport($this, 'summary')
            ->assertJsonPath('data.revenue', '72800.00')
            ->assertJsonPath('data.discount_total', '33600.00');
        vatReport($this, 'audit')->assertJsonPath('data.discount_count', 2);
    });

    it('kasir dalam batas 10% tidak butuh PIN', function () {
        // 10% × 48.000 = 4.800 → dasar 43.200 × 1,1655 = 50.349,60 → 50.300
        vatCheckout($this, 2, 'cash', 100000, vatDiscount(10))
            ->assertCreated()
            ->assertJsonPath('data.approved_by', null)
            ->assertJsonPath('data.grand_total', '50300.00');
    });
});
