<?php

declare(strict_types=1);

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->pos = posSetup();
});

/** 2× Es Kopi Large + 1 Croissant = 78.000 → service 3.900 → pajak 9.009 → 90.909 → bulat 90.900. */
function cart(stdClass $pos, array $override = []): array
{
    return [
        'id' => uuid(),
        'order_type' => 'takeaway',
        'items' => [
            ['product_id' => $pos->coffee->id, 'qty' => 2, 'option_ids' => [$pos->large->id], 'notes' => 'less ice'],
            ['product_id' => $pos->croissant->id, 'qty' => 1],
        ],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 100000, 'tendered' => 100000]],
        ...$override,
    ];
}

function checkout(object $test, array $body, ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->postJson('/api/v1/checkout', $body, apiHeaders());
}

describe('sukses', function () {
    it('menghitung ulang semua nominal, memberi nomor order, mengurangi stok', function () {
        Carbon::setTestNow('2026-10-08 03:00:00');

        $response = checkout($this, cart($this->pos));

        $response->assertCreated()
            ->assertJsonPath('message', 'Transaksi berhasil disimpan')
            ->assertJsonPath('data.order_number', 'JKT01-261008-0001')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.subtotal', '78000.00')
            ->assertJsonPath('data.service_total', '3900.00')
            ->assertJsonPath('data.tax_total', '9009.00')
            ->assertJsonPath('data.rounding', '-9.00')
            ->assertJsonPath('data.grand_total', '90900.00')
            ->assertJsonPath('data.paid_total', '90900.00')
            ->assertJsonPath('data.change_total', '9100.00')
            ->assertJsonPath('data.items.0.product_name', 'Es Kopi Susu')
            ->assertJsonPath('data.items.0.unit_price', '22000.00')
            ->assertJsonPath('data.items.0.options_total', '5000.00')
            ->assertJsonPath('data.items.0.line_total', '54000.00')
            ->assertJsonPath('data.items.0.options.0.name', 'Large')
            ->assertJsonPath('data.payments.0.tendered', '100000.00')
            ->assertJsonPath('data.payments.0.change', '9100.00')
            ->assertJsonPath('meta.idempotent_replay', false);

        expect(stockOf($this->pos->croissant->id)->stock_qty)->toBe(9);

        $movement = StockMovement::allTenants()->sole();
        expect($movement->type)->toBe(StockMovementType::Sale)
            ->and($movement->qty_change)->toBe(-1)
            ->and($movement->reference_id)->toBe($response->json('data.id'));
    });

    it('nilai yang dikirim aplikasi (harga/total) diabaikan', function () {
        $body = cart($this->pos);
        $body['grand_total'] = 1;
        $body['items'][0]['price'] = 1;

        checkout($this, $body)->assertCreated()->assertJsonPath('data.grand_total', '90900.00');
    });

    it('snapshot harga tetap walau harga produk berubah kemudian', function () {
        $id = checkout($this, cart($this->pos))->json('data.id');
        $this->pos->coffee->update(['name' => 'Kopi Baru', 'price' => '99000.00']);

        $order = Order::allTenants()->with('items')->find($id);
        expect($order?->items->first()?->product_name)->toBe('Es Kopi Susu')
            ->and($order?->items->first()?->unit_price)->toBe('22000.00');
    });

    it('split payment debit + tunai: non-tunai pas, tunai menutup sisa dengan kembalian', function () {
        $body = cart($this->pos, ['payments' => [
            ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000, 'tendered' => 50000],
            ['id' => uuid(), 'payment_method_id' => $this->pos->methods['debit']->id, 'amount' => 40900, 'reference' => 'A12345'],
        ]]);

        checkout($this, $body)->assertCreated()
            ->assertJsonPath('data.payments.0.category', 'cash')
            ->assertJsonPath('data.payments.0.amount', '50000.00')
            ->assertJsonPath('data.payments.0.change', '0.00')
            ->assertJsonPath('data.payments.1.reference', 'A12345')
            ->assertJsonPath('data.change_total', '0.00');
    });

    it('nomor order berurutan per outlet per hari lokal (ADR 0001)', function () {
        Carbon::setTestNow('2026-10-08 16:30:00'); // 23.30 WIB
        expect(checkout($this, cart($this->pos))->json('data.order_number'))->toBe('JKT01-261008-0001');
        expect(checkout($this, cart($this->pos))->json('data.order_number'))->toBe('JKT01-261008-0002');

        Carbon::setTestNow('2026-10-08 17:30:00'); // 00.30 WIB keesokan hari, UTC masih tanggal 8
        expect(checkout($this, cart($this->pos))->json('data.order_number'))->toBe('JKT01-261009-0001');
    });
});

describe('idempotensi', function () {
    it('request ganda dengan id sama → satu order, stok dipotong sekali', function () {
        $body = cart($this->pos);

        $first = checkout($this, $body)->assertCreated();
        checkout($this, $body)->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));

        expect(Order::allTenants()->count())->toBe(1)
            ->and(Payment::allTenants()->count())->toBe(1)
            ->and(stockOf($this->pos->croissant->id)->stock_qty)->toBe(9);
    });

    it('id pembayaran yang sudah dipakai order lain ditolak', function () {
        $body = cart($this->pos);
        checkout($this, $body)->assertCreated();

        $reuse = cart($this->pos, ['payments' => $body['payments']]);
        $response = checkout($this, $reuse);

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor('payments.0.id', 'error.details');
    });
});

describe('validasi & aturan bisnis', function () {
    it('validasi bentuk data', function (Closure $mutate, string $field) {
        $response = checkout($this, $mutate(cart($this->pos)));

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor($field, 'error.details');
    })->with([
        'tanpa id' => [fn (array $b) => [...$b, 'id' => null], 'id'],
        'tanpa item' => [fn (array $b) => [...$b, 'items' => []], 'items'],
        'qty 0' => [fn (array $b) => array_replace_recursive($b, ['items' => [['qty' => 0]]]), 'items.0.qty'],
        'tipe order tidak dikenal' => [fn (array $b) => [...$b, 'order_type' => 'delivery'], 'order_type'],
        'diskon persen > 100' => [fn (array $b) => [...$b, 'discount' => ['type' => 'percent', 'value' => 150]], 'discount.value'],
        'nominal bayar 0' => [fn (array $b) => array_replace_recursive($b, ['payments' => [['amount' => 0]]]), 'payments.0.amount'],
    ]);

    it('tanpa shift terbuka → 409 SHIFT_NOT_OPEN', function () {
        $this->pos->shift->forceFill(['status' => 'closed', 'open_device_key' => null])->save();

        assertApiError(checkout($this, cart($this->pos)), 'SHIFT_NOT_OPEN', 409);
    });

    it('token tanpa device kasir → DEVICE_NOT_REGISTERED', function () {
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

        assertApiError(checkout($this, cart($this->pos), userToken($owner)), 'DEVICE_NOT_REGISTERED', 403);
    });

    it('produk di kategori nonaktif ditolak; grup opsi nonaktif diabaikan (Q27)', function () {
        $category = TenantContext::run($this->pos->tenantId, fn () => Category::factory()->create(['is_active' => false]));
        $this->pos->croissant->update(['category_id' => $category->id]);

        checkout($this, cart($this->pos))->assertJsonPath('error.details', ['items.1.product_id' => ['Produk tidak tersedia']]);

        // Grup Ukuran (wajib pilih 1) dinonaktifkan: boleh tanpa opsi, opsinya ditolak bila dikirim
        $this->pos->croissant->update(['category_id' => null]);
        $this->pos->size->update(['is_active' => false]);

        $withoutOption = cart($this->pos, ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1]]]);
        checkout($this, $withoutOption)->assertCreated();

        $withInactiveOption = cart($this->pos, ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->large->id]]]]);
        assertApiError(checkout($this, $withInactiveOption), 'VALIDATION_ERROR', 422);
    });

    it('menu habis / nonaktif ditolak per item', function () {
        stockOf($this->pos->croissant)->forceFill(['is_available' => false])->save();
        $response = checkout($this, cart($this->pos));
        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonPath('error.details', ['items.1.product_id' => ['Croissant sedang habis']]);

        $this->pos->croissant->update(['is_active' => false]);
        checkout($this, cart($this->pos))->assertJsonPath('error.details', ['items.1.product_id' => ['Produk tidak tersedia']]);
    });

    it('grup opsi wajib (Ukuran) harus dipilih tepat 1', function (array $optionIds) {
        $body = cart($this->pos);
        $body['items'][0]['option_ids'] = array_map(fn (string $key) => $this->pos->{$key}->id, $optionIds);

        assertApiError(checkout($this, $body), 'VALIDATION_ERROR', 422);
    })->with([[[]], [['regular', 'large']]]);

    it('opsi yang bukan milik produk ditolak', function () {
        $body = cart($this->pos);
        $body['items'][1]['option_ids'] = [$this->pos->large->id];

        checkout($this, $body)->assertJsonPath('error.details', ['items.1.option_ids' => ['Opsi tidak valid untuk Croissant']]);
    });

    it('debit/kredit wajib kode approval', function () {
        $body = cart($this->pos, ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['credit']->id, 'amount' => 90900]]]);

        $response = checkout($this, $body);
        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor('payments.0.reference', 'error.details');
    });

    it('non-tunai melebihi sisa tagihan → PAYMENT_EXCEEDS_BALANCE', function () {
        $body = cart($this->pos, ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 100000]]]);

        $response = checkout($this, $body);
        assertApiError($response, 'PAYMENT_EXCEEDS_BALANCE', 422);
        $response->assertJsonPath('error.details.remaining', '90900.00');
    });

    it('pembayaran kurang ditolak tanpa menyimpan apa pun', function () {
        $body = cart($this->pos, ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]]]);

        assertApiError(checkout($this, $body), 'VALIDATION_ERROR', 422);
        expect(Order::allTenants()->count())->toBe(0)
            ->and(stockOf($this->pos->croissant->id)->stock_qty)->toBe(10);
    });
});

describe('isolasi tenant', function () {
    it('produk & metode bayar tenant lain ditolak', function () {
        $foreignProduct = Product::factory()->create();
        $foreignMethod = PaymentMethod::factory()->create();

        $body = cart($this->pos, [
            'items' => [['product_id' => $foreignProduct->id, 'qty' => 1]],
            'payments' => [['id' => uuid(), 'payment_method_id' => $foreignMethod->id, 'amount' => 1000]],
        ]);

        assertApiError(checkout($this, $body), 'VALIDATION_ERROR', 422);
        expect(Order::allTenants()->count())->toBe(0);
    });

    it('id order milik tenant lain tidak bisa dipakai ulang (404)', function () {
        $other = posSetup();
        $body = cart($other);
        checkout($this, $body, $other->token)->assertCreated();

        assertApiError(checkout($this, cart($this->pos, ['id' => $body['id']])), 'NOT_FOUND', 404);
    });
});

describe('diskon & PIN approval', function () {
    it('kasir dalam batas 10% boleh tanpa approval', function () {
        checkout($this, cart($this->pos, ['discount' => ['type' => 'fixed', 'value' => 7800]]))
            ->assertCreated()
            ->assertJsonPath('data.discount_total', '7800.00')
            ->assertJsonPath('data.approved_by', null);
    });

    it('kasir di atas batas tanpa approval → DISCOUNT_OVER_LIMIT', function () {
        $response = checkout($this, cart($this->pos, ['discount' => ['type' => 'percent', 'value' => 20]]));

        assertApiError($response, 'DISCOUNT_OVER_LIMIT', 422);
        $response->assertJsonPath('error.details.limit_percent', '10')
            ->assertJsonPath('error.details.discount_percent', '20.00');
    });

    it('dengan PIN supervisor → disetujui dan approved_by tercatat', function () {
        $body = cart($this->pos, [
            'discount' => ['type' => 'percent', 'value' => 20],
            'approver_user_id' => $this->pos->supervisor->id,
            'approver_pin' => '123456',
        ]);

        checkout($this, $body)->assertCreated()
            ->assertJsonPath('data.approved_by', $this->pos->supervisor->id)
            ->assertJsonPath('data.order_discount', '15600.00');
    });

    it('approver = pelaku → SELF_APPROVAL_NOT_ALLOWED', function () {
        $body = cart($this->pos, ['discount' => ['type' => 'percent', 'value' => 20], 'approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456']);

        assertApiError(checkout($this, $body), 'SELF_APPROVAL_NOT_ALLOWED', 403);
    });

    it('PIN approver salah → INVALID_PIN dan dihitung untuk penguncian', function () {
        $body = cart($this->pos, ['discount' => ['type' => 'percent', 'value' => 20], 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '000000']);

        assertApiError(checkout($this, $body), 'INVALID_PIN', 401);
        expect($this->pos->supervisor->refresh()->pin_failed_attempts)->toBe(1);
    });

    it('approver yang juga kasir (tanpa wewenang) ditolak', function () {
        $otherCashier = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        $body = cart($this->pos, ['discount' => ['type' => 'percent', 'value' => 20], 'approver_user_id' => $otherCashier->id, 'approver_pin' => '123456']);

        assertApiError(checkout($this, $body), 'FORBIDDEN', 403);
    });

    it('supervisor sendiri boleh diskon di atas batas tanpa approval', function () {
        checkout($this, cart($this->pos, ['discount' => ['type' => 'percent', 'value' => 50]]), userToken($this->pos->supervisor, $this->pos->device))
            ->assertCreated()
            ->assertJsonPath('data.approved_by', null);
    });
});

it('ringkasan shift memuat penjualan & kas seharusnya setelah checkout', function () {
    checkout($this, cart($this->pos))->assertCreated();
    checkout($this, cart($this->pos, ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 90900]]]))->assertCreated();

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->pos->shift->id}/summary", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.summary.order_count', 2)
        ->assertJsonPath('data.summary.sales_total', '181800.00')
        ->assertJsonPath('data.summary.cash_sales', '90900.00')
        ->assertJsonPath('data.summary.expected_cash', '290900.00');
});

it('regresi: opsi yang sama di dua item berbeda tidak dianggap ganda', function () {
    $body = cart($this->pos);
    $body['items'][] = ['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->large->id, $this->pos->large->id]];
    $body['payments'][0]['tendered'] = 200000;

    checkout($this, $body)->assertCreated()->assertJsonCount(3, 'data.items');
});

describe('verifikasi email owner (ADR 0009, Q31)', function () {
    it('checkout & tambah pembayaran ditolak sampai email owner diverifikasi', function () {
        $owner = User::factory()->owner()->unverified()->create(['tenant_id' => $this->pos->tenantId]);

        assertApiError(checkout($this, cart($this->pos)), 'EMAIL_NOT_VERIFIED', 403);

        $owner->forceFill(['email_verified_at' => now()])->save();
        checkout($this, cart($this->pos))->assertCreated();
    });
});
