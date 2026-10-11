<?php

declare(strict_types=1);

use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VoidOrder;
use App\Enums\StampEntryType;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\StampPrograms\Pages\ManageStampPrograms;
use App\Filament\Dashboard\Resources\StampPrograms\StampProgramResource;
use App\Models\Member;
use App\Models\MemberStamp;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StampProgram;
use App\Models\User;
use App\Services\Member\StampCards;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Kartu stempel M6 (ADR 0013, Q65). Program "Roti" (3 stempel = 1 gratis): Croissant 24.000 & Donat 12.000.
 * Es Kopi Susu 22.000 (wajib pilih ukuran) tidak termasuk. Service 5%, pajak 11%, pembulatan Rp100.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->donut = Product::factory()->create(['name' => 'Donat', 'price' => '12000.00']);
    $this->program = app(SaveStampProgram::class)->handle(null, [
        'name' => 'Roti', 'stamps_required' => 3, 'product_ids' => [$this->pos->croissant->id, $this->donut->id],
    ]);
    $this->member = Member::factory()->create(['name' => 'Rina']);
});

/** @param list<array<string, mixed>> $items */
function stampSale(object $test, array $items, array $stamps = [], array $override = []): Order
{
    return app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id,
        'stamp_program_ids' => $stamps,
        'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 500000]],
        ...$override,
    ]))['order'];
}

function stampBalance(object $test): int
{
    return app(StampCards::class)->balances($test->member)[$test->program->id] ?? 0;
}

it('order selesai: 1 item yang memenuhi = 1 stempel; item lain tidak', function () {
    $order = stampSale($this, [
        ['product_id' => $this->pos->croissant->id, 'qty' => 2],
        ['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]],
    ]);

    expect($order->stamps_earned)->toBe(2)->and(stampBalance($this))->toBe(2);

    $entry = MemberStamp::query()->sole();
    expect($entry->type)->toBe(StampEntryType::Earn)->and($entry->balance_after)->toBe(2)->and($entry->order_id)->toBe($order->id);
});

it('tukar gratis: item termurah yang memenuhi jadi potongan; item gratis tidak menghasilkan stempel', function () {
    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 3]]);
    expect(stampBalance($this))->toBe(3);

    // Croissant 24.000 + Donat 12.000 → Donat gratis. Dasar 24.000 → service 1.200 → pajak 2.772 → 27.972 → 28.000
    $order = stampSale($this, [
        ['product_id' => $this->pos->croissant->id, 'qty' => 1],
        ['product_id' => $this->donut->id, 'qty' => 1],
    ], [$this->program->id]);

    expect($order->stamp_free_total)->toBe('12000.00')
        ->and($order->discount_total)->toBe('12000.00')
        ->and($order->grand_total)->toBe('28000.00')
        ->and($order->stamp_redemptions)->toHaveCount(1)
        ->and($order->stamp_redemptions[0]['product_name'])->toBe('Donat')
        // 2 item memenuhi − 1 gratis = 1 stempel; saldo 3 − 3 + 1
        ->and($order->stamps_earned)->toBe(1)
        ->and(stampBalance($this))->toBe(1)
        // Poin dari subtotal setelah gratis (24.000 → 2 poin)
        ->and($order->points_earned)->toBe(2);
});

it('ditolak: stempel kurang, tanpa item yang memenuhi, tanpa member, outlet dikecualikan', function () {
    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 2]]);
    $count = Order::query()->count();

    $fails = function (Closure $sale, string $message): void {
        try {
            $sale();
            $this->fail('Seharusnya ditolak');
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe('VALIDATION_ERROR')->and($e->getMessage())->toContain($message)
                ->and($e->details)->toHaveKey('stamp_program_ids');
        }
    };

    $fails(fn () => stampSale($this, [['product_id' => $this->donut->id, 'qty' => 1]], [$this->program->id]), 'belum cukup');

    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);
    $count++;
    $fails(fn () => stampSale($this, [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]]], [$this->program->id]), 'Tidak ada item');
    $fails(fn () => stampSale($this, [['product_id' => $this->donut->id, 'qty' => 1]], [$this->program->id], ['member_id' => null]), 'Pasang member');

    app(UpdateMemberProgram::class)->handle(true, []);
    $fails(fn () => stampSale($this, [['product_id' => $this->donut->id, 'qty' => 1]], [$this->program->id]), 'tidak berlaku');

    expect(Order::query()->count())->toBe($count)->and(stampBalance($this))->toBe(3);
});

it('void mengembalikan stempel yang ditukar dan menarik stempel yang didapat; kiriman ulang hanya sekali', function () {
    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 3]]);

    $id = uuid();
    $data = ['id' => $id, 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]]];
    $order = stampSale($this, $data['items'], [$this->program->id], ['id' => $id]);
    stampSale($this, $data['items'], [$this->program->id], ['id' => $id]);

    // 3 − 3 + (2 − 1) = 1; replay tidak menukar dua kali
    expect(stampBalance($this))->toBe(1)
        ->and(MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::Redeem)->count())->toBe(1);

    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Salah', null);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order->refresh(), 'Salah', null);

    expect(stampBalance($this))->toBe(3)
        ->and(MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::VoidReversal)->count())->toBe(2);
});

it('open bill: gratis dicek ulang saat lunas sehingga stempel tidak terpakai dua kali', function () {
    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 3]]);

    $billId = uuid();
    app(SaveOpenBill::class)->handle($this->pos->cashier, $this->pos->device, $billId, OpenBillData::fromArray([
        'member_id' => $this->member->id, 'stamp_program_ids' => [$this->program->id],
        'items' => [['product_id' => $this->donut->id, 'qty' => 2]],
    ]));
    $bill = Order::query()->findOrFail($billId);
    expect($bill->stamp_free_total)->toBe('12000.00');

    // Ubah item tanpa field stamp_program_ids (aplikasi lama): pilihan gratis tetap
    app(SaveOpenBill::class)->handle($this->pos->cashier, $this->pos->device, $billId, OpenBillData::fromArray([
        'items' => [['product_id' => $this->donut->id, 'qty' => 3]],
    ]));
    expect($bill->refresh()->stamp_free_total)->toBe('12000.00');

    // Kasir lain memakai stempel yang sama lebih dulu (Donat gratis, Croissant dibayar)
    stampSale($this, [['product_id' => $this->donut->id, 'qty' => 1], ['product_id' => $this->pos->croissant->id, 'qty' => 1]], [$this->program->id]);

    expect(fn () => app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $bill->refresh(), [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => '100000', 'tendered' => null, 'reference' => null],
    ]))->toThrow(BusinessException::class, 'belum cukup');

    // Saldo 3 − 3 + 1 (Croissant di order kasir lain); open bill tetap terbuka
    expect($bill->refresh()->status->value)->toBe('open')->and(stampBalance($this))->toBe(1);
});

it('isolasi tenant: program & produk bisnis lain tidak bisa dipakai', function () {
    $programA = $this->program;
    $other = posSetup();
    TenantContext::set($other->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]);
    $memberB = Member::factory()->create();

    expect(StampProgram::query()->count())->toBe(0);

    expect(fn () => app(CheckoutOrder::class)->handle($other->cashier, $other->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $memberB->id, 'stamp_program_ids' => [$programA->id],
        'items' => [['product_id' => $other->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $other->methods['cash']->id, 'amount' => 100000]],
    ])))->toThrow(BusinessException::class, 'tidak ditemukan');

    expect(fn () => app(SaveStampProgram::class)->handle(null, [
        'name' => 'Curang', 'stamps_required' => 3, 'product_ids' => [$this->donut->id],
    ]))->toThrow(BusinessException::class, 'Produk atau kategori tidak ditemukan');
});

it('API: checkout dengan stamp_program_ids, detail member memuat stamp_cards, validasi gagal', function () {
    stampSale($this, [['product_id' => $this->pos->croissant->id, 'qty' => 3]]);

    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.stamp_cards.0.name', 'Roti')
        ->assertJsonPath('data.stamp_cards.0.balance', 3)
        ->assertJsonPath('data.stamp_cards.0.rewards_available', 1);

    $body = [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $this->member->id,
        'stamp_program_ids' => ['bukan-uuid'],
        'items' => [['product_id' => $this->donut->id, 'qty' => 1]],
        'payments' => [],
    ];
    assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/checkout', $body, apiHeaders()), 'VALIDATION_ERROR', 422);

    $body['stamp_program_ids'] = [$this->program->id];
    $this->withToken($this->pos->token)->postJson('/api/v1/checkout', $body, apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.grand_total', '0.00')
        ->assertJsonPath('data.stamp_free_total', '12000.00')
        ->assertJsonPath('data.stamp_redemptions.0.product_name', 'Donat');

    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$body['id']}/receipt", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.totals.stamp_free_total', '12000.00')
        ->assertJsonPath('data.member_stamps.0.balance', 0);
});

it('dashboard: owner membuat kartu stempel; manager & supervisor tidak melihat menu', function () {
    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    Livewire::test(ManageStampPrograms::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Kopi', 'stamps_required' => 1, 'product_ids' => []])
        ->assertHasActionErrors(['stamps_required']);

    Livewire::test(ManageStampPrograms::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Kopi', 'stamps_required' => 10, 'product_ids' => [$this->pos->coffee->id]])
        ->assertHasNoActionErrors();

    expect(StampProgram::query()->where('name', 'Kopi')->sole()->productIds())->toBe([$this->pos->coffee->id]);

    $outlet = Outlet::query()->findOrFail($this->pos->outlet->id);
    $this->actingAs(User::factory()->manager()->forOutlet($outlet)->create());
    expect(StampProgramResource::canViewAny())->toBeFalse();
    $this->get(StampProgramResource::getUrl())->assertForbidden();
});
