<?php

declare(strict_types=1);

use App\Actions\Stock\CancelStockReceipt;
use App\Actions\Stock\CreateStockOpname;
use App\Actions\Stock\PostStockOpname;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\ReceiveStockTransfer;
use App\Actions\Stock\SaveOpnameCounts;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveStockTransfer;
use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SendStockTransfer;
use App\Enums\ReportTab;
use App\Exports\ReportSectionBuilder;
use App\Filament\Dashboard\Widgets\Reports\PurchasesBySupplierTableWidget;
use App\Filament\Dashboard\Widgets\Reports\StockTableWidget;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Laporan stok & pembelian I4 (ADR 0015, Q83). 12 Okt 2026 WIB. Croissant lacak stok 10 di JKT01, modal 9.000.
 * Stok masuk 10 @ 12.000 (PT Roti), 5 @ 6.000 (tanpa supplier, dibatalkan), opname −1, transfer 3 ke Cilandak.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-12 03:00:00');
    $this->croissant = $this->pos->croissant;
    $this->croissant->forceFill(['cost_price' => '9000.00'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->cilandak = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId, 'name' => 'Cilandak']);
    $supplier = app(SaveSupplier::class)->handle(null, ['name' => 'PT Roti']);
    $receive = fn (array $items, ?string $supplierId) => app(PostStockReceipt::class)->handle(app(SaveStockReceipt::class)->handle(null, $this->pos->outlet, [
        'received_on' => '2026-10-12', 'supplier_id' => $supplierId, 'items' => $items,
    ]), $this->owner);

    // (10 × 9.000 + 10 × 12.000) ÷ 20 = 10.500
    $receive([['product_id' => $this->croissant->id, 'qty' => 10, 'unit_cost' => '12000']], $supplier->id);
    $cancelled = $receive([['product_id' => $this->croissant->id, 'qty' => 5, 'unit_cost' => '6000']], null);
    app(CancelStockReceipt::class)->handle($cancelled, $this->owner, 'Salah');

    $opname = app(CreateStockOpname::class)->handle($this->pos->outlet, ['counted_on' => '2026-10-12']);
    app(SaveOpnameCounts::class)->handle($opname, [$opname->items->firstWhere('product_id', $this->croissant->id)->id => 19]);
    app(PostStockOpname::class)->handle($opname, $this->owner);

    $transfer = app(SaveStockTransfer::class)->handle(null, $this->owner, [
        'from_outlet_id' => $this->pos->outlet->id, 'to_outlet_id' => $this->cilandak->id, 'items' => [['product_id' => $this->croissant->id, 'qty' => 3]],
    ]);
    app(ReceiveStockTransfer::class)->handle(app(SendStockTransfer::class)->handle($transfer, $this->owner), $this->owner);
});

afterEach(fn () => Carbon::setTestNow());

function stockRange(): array
{
    return [CarbonImmutable::parse('2026-10-11 17:00'), CarbonImmutable::parse('2026-10-12 16:59:59')];
}

it('pergerakan stok per outlet: masuk bersih, opname, transfer, nilai stok', function () {
    CurrentOutlet::set(null, $this->pos->outlet->id);
    $row = collect(app(ReportService::class)->stock(...stockRange()))->firstWhere('product_name', 'Croissant');

    // 10 + 10 − 0 (5 masuk lalu batal) − 1 opname − 3 transfer = 16; modal (20 × 10.500 + 5 × 6.000) ÷ 25 = 9.600
    // (batal tidak memutar balik modal, Q80) → nilai 16 × 9.600
    expect($row)->toMatchArray(['purchased' => 10, 'opname' => -1, 'transfer' => -3, 'net_change' => 6, 'stock' => 16, 'stock_value' => '153600.00']);

    CurrentOutlet::set(null, $this->cilandak->id);
    $row = collect(app(ReportService::class)->stock(...stockRange()))->firstWhere('product_name', 'Croissant');
    expect($row)->toMatchArray(['transfer' => 3, 'stock' => 3]);
});

it('pembelian per supplier & produk: hanya yang diposting, tanpa yang dibatalkan', function () {
    CurrentOutlet::set(null, $this->pos->outlet->id);
    $p = app(ReportService::class)->purchases(...stockRange());

    expect($p['suppliers'])->toBe([['supplier_id' => $p['suppliers'][0]['supplier_id'], 'supplier_name' => 'PT Roti', 'receipt_count' => 1, 'qty' => 10, 'total_cost' => '120000.00']])
        ->and($p['products'][0])->toMatchArray(['product_name' => 'Croissant', 'qty' => 10, 'average_cost' => '12000.00', 'total_cost' => '120000.00'])
        ->and($p['totals'])->toBe(['receipt_count' => 1, 'qty' => 10, 'total_cost' => '120000.00']);

    // Di luar periode → kosong
    expect(app(ReportService::class)->purchases(CarbonImmutable::parse('2026-10-01 00:00'), CarbonImmutable::parse('2026-10-05 00:00'))['totals']['qty'])->toBe(0);
});

it('dashboard & export: widget tampil, export tab Stok memuat pembelian; API purchases hanya report.view', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner, $this->pos->outlet->id);

    $range = ['preset' => 'custom', 'from' => '2026-10-12', 'to' => '2026-10-12'];
    Livewire::test(StockTableWidget::class, ['pageFilters' => $range])->assertSee('Stok masuk')->assertSee('Rp153.600');
    Livewire::test(PurchasesBySupplierTableWidget::class, ['pageFilters' => $range])->assertSee('PT Roti')->assertSee('Rp120.000');

    $titles = array_map(fn ($s) => $s->title, app(ReportSectionBuilder::class)->build([ReportTab::Stock], ReportContext::fromInput($range, $this->owner), 1000));
    expect($titles)->toBe(['Pergerakan stok', 'Pembelian per supplier', 'Pembelian per produk']);

    freshAuth();
    $this->withToken(userToken($this->owner))->getJson('/api/v1/reports/purchases?from=2026-10-12&to=2026-10-12', apiHeaders())
        ->assertOk()->assertJsonPath('data.totals.total_cost', '120000.00');
    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson('/api/v1/reports/purchases?from=2026-10-12&to=2026-10-12', apiHeaders()), 'FORBIDDEN', 403);
});

it('isolasi tenant: pembelian bisnis lain tidak terhitung', function () {
    $other = posSetup();
    TenantContext::set($other->tenantId);
    CurrentOutlet::set(null, $other->outlet->id);

    expect(app(ReportService::class)->purchases(...stockRange())['totals']['qty'])->toBe(0)
        ->and(Product::query()->whereKey($this->croissant->id)->exists())->toBeFalse();
});
