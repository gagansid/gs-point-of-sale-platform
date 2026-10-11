<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\ReportTab;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Widgets\Reports\CategoriesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\EmployeesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ProductsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportTrendChartWidget;
use App\Filament\Dashboard\Widgets\Reports\ShiftsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\StockTableWidget;
use App\Filament\Dashboard\Widgets\Reports\VoidsTableWidget;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Halaman Laporan (SPEC Q49/Q50): tab per izin, batas 31 hari supervisor di server, granularitas. */
function pageSale(stdClass $pos, string $utc, int $qty = 1): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 500000]],
    ]))['order']);
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

    pageSale($this->pos, '2026-08-20 03:00:00', 3);  // 51 hari lalu: di luar batas supervisor
    pageSale($this->pos, '2026-10-08 03:00:00');      // 2 hari lalu

    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('owner: semua tab kecuali Outlet (hanya 1 outlet); bawaan bulan ini', function () {
    $tabs = array_map(fn (ReportTab $t) => $t->value, Livewire::test(Reports::class)->instance()->visibleTabs());

    expect($tabs)->not->toContain('outlets')->toContain('stock', 'categories', 'shifts');

    Livewire::test(Reports::class)
        ->assertSet('filters.preset', 'this_month')
        ->assertSet('urlPeriod', 'this_month')
        ->set('tab', 'products')
        ->assertSet('tab', 'products')
        ->set('tab', 'outlets')
        // Tab tidak relevan → kembali ke tab pertama yang boleh
        ->assertSet('tab', 'summary');
});

it('supervisor: hanya tab operasional, preset > 31 hari disembunyikan, rentang lama dipotong', function () {
    $this->actingAs($this->pos->supervisor);
    CurrentOutlet::set($this->pos->supervisor);

    $page = Livewire::test(Reports::class);
    expect(array_map(fn (ReportTab $t) => $t->value, $page->instance()->visibleTabs()))
        ->toBe(['summary', 'products', 'payment_methods', 'employees', 'shifts', 'discounts']);

    $page->set('tab', 'stock')->assertSet('tab', 'summary');

    // Kustom 1 Agu–10 Okt → dipotong ke 10 Sep (31 hari termasuk hari ini)
    Livewire::withQueryParams(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-10'])
        ->test(Reports::class)
        ->assertSet('filters.from', fn (?string $v): bool => substr((string) $v, 0, 10) === '2026-09-10');

    Livewire::withQueryParams(['period' => 'last_year'])->test(Reports::class)->assertSet('filters.preset', 'this_month');

    // Widget di server juga memotong: penjualan 20 Agu tidak terhitung
    Livewire::test(ReportStatsWidget::class, ['pageFilters' => ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-10']])
        ->assertSee('Rp28.000')->assertDontSee('Rp111.900');
});

it('owner melihat penjualan lama; granularitas mengikuti rentang', function () {
    $range = ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-10', 'compare' => 'none'];

    Livewire::test(ReportStatsWidget::class, ['pageFilters' => $range])->assertSee('4'); // item terjual 3 + 1
    Livewire::test(ReportTrendChartWidget::class, ['pageFilters' => [...$range, 'granularity' => 'week']])->assertSee('Tren omzet per minggu');
    // 71 hari: per jam tidak diizinkan → bawaan per minggu
    Livewire::test(ReportTrendChartWidget::class, ['pageFilters' => [...$range, 'granularity' => 'hour']])->assertSee('Tren omzet per minggu');
    Livewire::test(ReportTrendChartWidget::class, ['pageFilters' => [...$range, 'granularity' => 'day']])->assertSee('Tren omzet per hari');

    Livewire::test(Reports::class)
        ->set('filters.preset', 'this_year')
        ->assertSet('filters.granularity', 'month')
        ->set('filters.granularity', 'week')
        ->assertSet('urlGroup', 'week');
});

it('widget tabel: produk, kategori, karyawan, shift, stok, void', function () {
    $range = ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-10-10'];

    Livewire::test(ProductsTableWidget::class, ['pageFilters' => $range])->assertSee('Croissant')->assertSee('4 item');
    Livewire::test(CategoriesTableWidget::class, ['pageFilters' => $range])->assertSee('Tanpa kategori');
    Livewire::test(EmployeesTableWidget::class, ['pageFilters' => $range])->assertSee('Budi');
    Livewire::test(ShiftsTableWidget::class, ['pageFilters' => ['preset' => 'today']])->assertSee('Rekap shift');
    Livewire::test(StockTableWidget::class, ['pageFilters' => $range])->assertSee('Croissant');
    Livewire::test(VoidsTableWidget::class, ['pageFilters' => $range])->assertSee('Tidak ada void');
});

it('tab non-operasional tertutup untuk supervisor di level widget', function () {
    $this->actingAs($this->pos->supervisor);

    expect(StockTableWidget::canView())->toBeFalse()
        ->and(CategoriesTableWidget::canView())->toBeFalse()
        ->and(ProductsTableWidget::canView())->toBeTrue()
        ->and(VoidsTableWidget::canView())->toBeTrue();
});

it('cetak ulang struk dashboard tertutup untuk supervisor (menu Penjualan)', function () {
    $order = Order::query()->latest('completed_at')->firstOrFail();
    $this->actingAs($this->pos->supervisor);

    $this->get("/dashboard/orders/{$order->id}/receipt")->assertForbidden();
});

it('isolasi tenant: tabel laporan tidak memuat produk tenant lain', function () {
    $other = posSetup();
    TenantContext::run($other->tenantId, fn () => $other->croissant->update(['name' => 'Roti Tenant Lain']));
    pageSale($other, '2026-10-08 03:00:00');
    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    Livewire::test(ProductsTableWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertSee('Croissant')->assertDontSee('Roti Tenant Lain');
});
