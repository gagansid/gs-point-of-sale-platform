<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Product\AdjustStock;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Dashboard\Resources\StockMovements\StockMovementResource;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Stok → Riwayat stok (Q14, ADR 0015): kartu stok hanya baca, terfilter outlet & produk. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    // Croissant (stok 10): terjual 2 lewat kasir, lalu owner menambah 5
    $this->order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ]))['order'];
    app(AdjustStock::class)->handle($this->pos->croissant, $this->pos->outlet, $this->owner, uuid(), 5, 'Barang datang dari pusat');
});

it('menampilkan penjualan & penyesuaian dengan stok akhir, dokumen, dan alasan', function () {
    $movements = StockMovement::query()->where('product_id', $this->pos->croissant->id)->get();

    expect($movements)->toHaveCount(2);

    Livewire::test(ListStockMovements::class)
        ->assertCanSeeTableRecords($movements)
        ->assertSee('Croissant')
        ->assertSee('Penjualan')->assertSee('-2')
        ->assertSee('Penyesuaian')->assertSee('+5')
        // Dokumen penjualan = nomor order (tautan ke detail penjualan)
        ->assertSee($this->order->order_number)
        ->assertSee('Barang datang dari pusat');

    $this->get(StockMovementResource::getUrl())->assertOk()->assertSee('Riwayat stok');
});

it('tab Masuk/Keluar memisahkan arah perubahan', function () {
    $sale = StockMovement::query()->where('qty_change', '<', 0)->get();
    $adjustment = StockMovement::query()->where('qty_change', '>', 0)->get();

    Livewire::test(ListStockMovements::class)
        ->set('activeTab', 'in')->assertCanSeeTableRecords($adjustment)->assertCanNotSeeTableRecords($sale)
        ->set('activeTab', 'out')->assertCanSeeTableRecords($sale)->assertCanNotSeeTableRecords($adjustment);
});

it('dibuka dari Produk: terfilter ke produk itu tanpa batas tanggal', function () {
    $other = Product::factory()->tracked(3)->create(['name' => 'Bagel']);
    app(AdjustStock::class)->handle($other, $this->pos->outlet, $this->owner, uuid(), 1, 'Koreksi');
    $this->travel(3)->days();

    Livewire::withQueryParams(['product' => $this->pos->croissant->id])->test(ListStockMovements::class)
        ->assertSet('tableFilters.product_id.value', $this->pos->croissant->id)
        ->assertCanSeeTableRecords(StockMovement::query()->where('product_id', $this->pos->croissant->id)->get())
        ->assertCanNotSeeTableRecords(StockMovement::query()->where('product_id', $other->id)->get());

    Livewire::test(ListProducts::class)
        ->assertActionVisible(TestAction::make('stockHistory')->table($this->pos->croissant))
        ->assertActionHidden(TestAction::make('stockHistory')->table($this->pos->coffee));
});

it('mengikuti pemilih outlet: outlet lain tidak tampil saat satu outlet dipilih', function () {
    $cilandak = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId, 'name' => 'Cilandak']);
    $there = app(AdjustStock::class)->handle($this->pos->croissant, $cilandak, $this->owner, uuid(), 4, 'Stok awal Cilandak')['movement'];
    $this->owner->forgetOutletIds();

    CurrentOutlet::set($this->owner, $this->pos->outlet->id);
    Livewire::test(ListStockMovements::class)->assertCanNotSeeTableRecords([$there]);

    CurrentOutlet::set($this->owner, $cilandak->id);
    Livewire::test(ListStockMovements::class)->assertCanSeeTableRecords([$there]);
});

it('isolasi tenant: riwayat stok tenant lain tidak terlihat', function () {
    $other = posSetup();
    $foreign = TenantContext::run($other->tenantId, fn () => app(AdjustStock::class)
        ->handle($other->croissant, $other->outlet, User::factory()->owner()->create(['tenant_id' => $other->tenantId]), uuid(), 7, 'Tetangga')['movement']);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    Livewire::test(ListStockMovements::class)->assertCanNotSeeTableRecords([$foreign])->assertDontSee('Tetangga');
});

it('permission: kasir & supervisor ditolak; manager boleh; hanya baca', function () {
    $this->actingAs($this->pos->cashier);
    expect(StockMovementResource::canViewAny())->toBeFalse();
    $this->get(StockMovementResource::getUrl())->assertForbidden();

    $this->actingAs($this->pos->supervisor);
    expect(StockMovementResource::canViewAny())->toBeFalse();

    $this->actingAs(User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]));
    expect(StockMovementResource::canViewAny())->toBeTrue()
        ->and(StockMovementResource::canCreate())->toBeFalse()
        ->and(StockMovementResource::canEdit(StockMovement::query()->firstOrFail()))->toBeFalse()
        ->and(StockMovementResource::canDelete(StockMovement::query()->firstOrFail()))->toBeFalse();
});
