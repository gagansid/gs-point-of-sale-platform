<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\RefundData;
use App\Actions\Order\RefundOrder;
use App\Enums\StockMovementType;
use App\Filament\Dashboard\Resources\Orders\Pages\ListOrders;
use App\Filament\Dashboard\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Penjualan → tab Diretur (ADR 0018) dan dokumen retur di Riwayat stok. Croissant 24.000 → 28.000 per item. */
function refundListSale(stdClass $pos, int $qty): Order
{
    return app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 200000]],
    ]))['order'];
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);

    $this->kept = refundListSale($this->pos, 1);
    $this->refunded = refundListSale($this->pos, 2);
    app(RefundOrder::class)->handle($this->pos->supervisor, $this->pos->device, $this->refunded, RefundData::fromArray([
        'id' => uuid(), 'reason' => 'Roti gosong', 'method' => 'cash', 'restock' => true,
        'items' => [['order_item_id' => $this->refunded->items()->value('id'), 'qty' => 1]],
    ]));

    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

it('tab Diretur hanya memuat order dengan retur; status menyebut nilai retur', function () {
    expect($this->refunded->refresh()->refunded_total)->not->toBe('0.00');

    Livewire::test(ListOrders::class)
        ->assertSee('Diretur Rp')
        ->set('activeTab', 'refunded')
        ->assertCanSeeTableRecords([$this->refunded])
        ->assertCanNotSeeTableRecords([$this->kept]);
});

it('riwayat stok: barang retur kembali ke stok dengan dokumen "Retur {nomor order}"', function () {
    $movement = StockMovement::query()->where('type', StockMovementType::Refund)->sole();

    expect($movement->qty_change)->toBe(1);

    Livewire::test(ListStockMovements::class)
        ->assertCanSeeTableRecords([$movement])
        ->assertSee('Retur pelanggan')
        ->assertSee('Retur '.$this->refunded->order_number);
});
