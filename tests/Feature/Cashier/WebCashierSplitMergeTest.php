<?php

declare(strict_types=1);

use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/** Pecah & gabung open bill di kasir web (ADR 0019). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($this->pos->webDevice, $this->pos->cashier)->create());
});

function webBill(object $test, int $qty, string $table): Order
{
    return TenantContext::run($test->pos->tenantId, fn (): Order => app(SaveOpenBill::class)->handle($test->pos->cashier, $test->pos->webDevice, uuid(), OpenBillData::fromArray([
        'order_type' => 'dine_in', 'table_label' => $table,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
    ]))['order']);
}

it('detail open bill: pecah ke bill baru lalu gabung kembali', function () {
    $bill = webBill($this, 3, 'Meja 5');
    $itemId = TenantContext::run($this->pos->tenantId, fn () => $bill->items()->value('id'));

    $this->get(route('cashier.orders.show', $bill->id))->assertOk()->assertSee('Pecah bill')->assertSee('Gabung bill');

    $newId = (string) Str::uuid7();
    $this->post(route('cashier.orders.split', $bill->id), [
        'new_order_id' => $newId, 'table_label' => 'Meja 5B', 'items' => [['order_item_id' => $itemId, 'qty' => 1]],
    ])->assertRedirect(route('cashier.orders.show', $newId));

    expect(Order::allTenants()->findOrFail($newId)->table_label)->toBe('Meja 5B');
    $this->get(route('cashier.orders.show', $bill->id))->assertOk()->assertSee('Meja 5B');

    $this->post(route('cashier.orders.merge', $bill->id), ['source_order_id' => $newId])->assertRedirect(route('cashier.orders.show', $bill->id));
    expect(Order::allTenants()->findOrFail($newId)->status)->toBe(OrderStatus::Merged)
        ->and(TenantContext::run($this->pos->tenantId, fn () => (int) $bill->items()->sum('qty')))->toBe(3);
});

it('pecah semua item ditolak dengan pesan', function () {
    $bill = webBill($this, 1, 'Meja 6');
    $itemId = TenantContext::run($this->pos->tenantId, fn () => $bill->items()->value('id'));

    $this->post(route('cashier.orders.split', $bill->id), ['new_order_id' => (string) Str::uuid7(), 'items' => [['order_item_id' => $itemId, 'qty' => 1]]])
        ->assertSessionHasErrors('cashier');
});
