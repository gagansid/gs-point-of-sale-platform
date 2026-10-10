<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->pos = posSetup();
    $this->orderId = uuid();
});

/** Open bill: 1 Es Kopi Regular + 2 Croissant = 70.000 → service 3.500 → pajak 8.085 → 81.585 → 81.600. */
function bill(stdClass $pos, array $override = []): array
{
    return [
        'order_type' => 'dine_in',
        'table_label' => 'Meja 5',
        'items' => [
            ['product_id' => $pos->coffee->id, 'qty' => 1, 'option_ids' => [$pos->regular->id]],
            ['product_id' => $pos->croissant->id, 'qty' => 2],
        ],
        ...$override,
    ];
}

function putBill(object $test, string $id, array $body, ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->putJson("/api/v1/orders/{$id}", $body, apiHeaders());
}

function pay(object $test, string $id, array $payments, ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->postJson("/api/v1/orders/{$id}/payments", ['payments' => $payments], apiHeaders());
}

describe('open bill', function () {
    it('membuat open bill dengan nomor order tanpa memotong stok', function () {
        putBill($this, $this->orderId, bill($this->pos))
            ->assertCreated()
            ->assertJsonPath('message', 'Open bill berhasil disimpan')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.table_label', 'Meja 5')
            ->assertJsonPath('data.order_number', fn (string $n) => str_starts_with($n, 'JKT01-'))
            ->assertJsonPath('data.grand_total', '81600.00')
            ->assertJsonPath('data.paid_total', '0.00');

        expect(stockOf($this->pos->croissant->id)->stock_qty)->toBe(10)
            ->and(StockMovement::allTenants()->count())->toBe(0);
    });

    it('mengubah item mengganti seluruh item dan menghitung ulang; nomor order tetap', function () {
        $number = putBill($this, $this->orderId, bill($this->pos))->json('data.order_number');

        putBill($this, $this->orderId, bill($this->pos, ['items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]]))
            ->assertOk()
            ->assertJsonPath('message', 'Open bill berhasil diperbarui')
            ->assertJsonPath('data.order_number', $number)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.subtotal', '24000.00');
    });

    it('PUT ganda dengan isi sama tetap satu order', function () {
        putBill($this, $this->orderId, bill($this->pos))->assertCreated();
        putBill($this, $this->orderId, bill($this->pos))->assertOk();

        expect(Order::allTenants()->count())->toBe(1);
    });

    it('validasi: item wajib, id harus UUID', function () {
        assertApiError(putBill($this, $this->orderId, bill($this->pos, ['items' => []])), 'VALIDATION_ERROR', 422);

        freshAuth();
        $this->withToken($this->pos->token)->putJson('/api/v1/orders/bukan-uuid', bill($this->pos), apiHeaders())->assertNotFound();
    });

    it('order yang sudah selesai tidak bisa diubah → ORDER_ALREADY_CLOSED', function () {
        putBill($this, $this->orderId, bill($this->pos))->assertCreated();
        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 81600]])->assertCreated();

        assertApiError(putBill($this, $this->orderId, bill($this->pos)), 'ORDER_ALREADY_CLOSED', 409);
    });

    it('isolasi: id open bill milik tenant lain → 404', function () {
        $other = posSetup();
        putBill($this, $this->orderId, bill($other), $other->token)->assertCreated();

        assertApiError(putBill($this, $this->orderId, bill($this->pos)), 'NOT_FOUND', 404);
    });

    it('tanpa shift terbuka → SHIFT_NOT_OPEN', function () {
        $this->pos->shift->forceFill(['status' => 'closed', 'open_device_key' => null])->save();

        assertApiError(putBill($this, $this->orderId, bill($this->pos)), 'SHIFT_NOT_OPEN', 409);
    });
});

describe('tambah pembayaran', function () {
    beforeEach(function () {
        putBill($this, $this->orderId, bill($this->pos))->assertCreated();
    });

    it('split bill: bayar sebagian tetap open, pelunasan menyelesaikan order dan memotong stok', function () {
        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 40000]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.paid_total', '40000.00');

        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000, 'tendered' => 50000]])
            ->assertCreated()
            ->assertJsonPath('message', 'Pembayaran berhasil disimpan')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.paid_total', '81600.00')
            ->assertJsonPath('data.change_total', '8400.00')
            ->assertJsonCount(2, 'data.payments');

        expect(stockOf($this->pos->croissant->id)->stock_qty)->toBe(8);
    });

    it('pembayaran ganda dengan id sama tidak tercatat dua kali', function () {
        $payment = [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 30000]];

        pay($this, $this->orderId, $payment)->assertCreated();
        pay($this, $this->orderId, $payment)->assertOk()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.paid_total', '30000.00');

        expect(Payment::allTenants()->count())->toBe(1);
    });

    it('non-tunai melebihi sisa → PAYMENT_EXCEEDS_BALANCE', function () {
        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 80000]])->assertCreated();

        $response = pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['transfer']->id, 'amount' => 5000]]);
        assertApiError($response, 'PAYMENT_EXCEEDS_BALANCE', 422);
        $response->assertJsonPath('error.details.remaining', '1600.00');
    });

    it('validasi pembayaran', function () {
        assertApiError(pay($this, $this->orderId, [['id' => 'x', 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 0]]), 'VALIDATION_ERROR', 422);
    });

    it('order selesai tidak bisa dibayar lagi', function () {
        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 81600]])->assertCreated();

        assertApiError(pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 1000]]), 'ORDER_ALREADY_CLOSED', 409);
    });

    it('pelunasan di shift berikutnya: order pindah ke shift penerima uang (Q20)', function () {
        $firstShift = $this->pos->shift;
        $firstShift->forceFill(['status' => 'closed', 'open_device_key' => null])->save();
        $nextShift = TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create());

        pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 81600]])->assertCreated();

        expect(Order::allTenants()->find($this->orderId)?->shift_id)->toBe($nextShift->id);
    });

    it('isolasi: open bill tenant lain → 404', function () {
        $other = posSetup();
        $foreignId = uuid();
        putBill($this, $foreignId, bill($other), $other->token)->assertCreated();

        assertApiError(pay($this, $foreignId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 1000]]), 'NOT_FOUND', 404);
    });
});

it('total baru lebih kecil dari yang sudah dibayar ditolak', function () {
    putBill($this, $this->orderId, bill($this->pos))->assertCreated();
    pay($this, $this->orderId, [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 50000]])->assertCreated();

    assertApiError(putBill($this, $this->orderId, bill($this->pos, ['items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]])), 'VALIDATION_ERROR', 422);
    expect(Order::allTenants()->find($this->orderId)?->status)->toBe(OrderStatus::Open);
});
