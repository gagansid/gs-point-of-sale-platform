<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Report\ManageReportPresets;
use App\Enums\ReportTab;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Widgets\Reports\CategoriesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\EmployeesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PeakHoursTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ProductsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportTrendChartWidget;
use App\Filament\Dashboard\Widgets\Reports\ShiftsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\StockTableWidget;
use App\Filament\Dashboard\Widgets\Reports\TrendTableWidget;
use App\Filament\Dashboard\Widgets\Reports\VoidsTableWidget;
use App\Models\Order;
use App\Models\ReportPreset;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
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

    Livewire::test(ProductsTableWidget::class, ['pageFilters' => $range])->assertSee('Croissant')->assertSeeHtml('<tfoot>')->assertSee('produk)');
    Livewire::test(CategoriesTableWidget::class, ['pageFilters' => $range])->assertSee('Tanpa kategori')->assertSeeHtml('<tfoot>')->assertSee('100%');
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

it('filter lanjutan di panel samping: terapkan, badge, hapus', function () {
    Livewire::test(Reports::class)
        ->callAction('advancedFilters', data: ['order_type' => 'dine_in'])
        ->assertHasNoActionErrors()
        ->assertSet('filters.order_type', 'dine_in')
        ->assertSet('urlType', 'dine_in')
        ->assertSee('Tipe: Makan di tempat')
        ->callAction('advancedFilters', arguments: ['clear' => true])
        ->assertSet('filters.order_type', null)
        ->assertDontSee('Tipe: Makan di tempat');
});

it('link dengan sebagian parameter: sisanya dari bawaan halaman (bulan ini)', function () {
    Livewire::withQueryParams(['type' => 'dine_in'])
        ->test(Reports::class)
        ->assertSet('filters.preset', 'this_month')
        ->assertSet('filters.order_type', 'dine_in');
});

describe('preset filter (R8)', function () {
    it('simpan filter + tab, lalu pakai lagi dari kondisi lain', function () {
        $page = Livewire::test(Reports::class)
            ->set('filters.preset', 'last_7_days')->set('filters.compare', 'previous_year')->set('tab', 'products')
            ->callAction('savePreset', ['name' => 'Rekap mingguan'])
            ->assertNotified('Preset disimpan');

        $preset = ReportPreset::query()->sole();
        expect($preset->user_id)->toBe($this->owner->id)
            ->and($preset->filters['preset'])->toBe('last_7_days')
            ->and($preset->filters['tab'])->toBe('products')
            ->and($preset->filters)->not->toHaveKey('outlet');

        $page->set('filters.preset', 'today')->set('filters.compare', 'none')->set('tab', 'summary')
            ->callAction('applyPreset', ['preset_id' => $preset->id])
            ->assertSet('filters.preset', 'last_7_days')
            ->assertSet('filters.compare', 'previous_year')
            ->assertSet('tab', 'products');
    });

    it('nama sama menimpa; maks. 10 preset; preset user lain tidak bisa dipakai/dihapus', function () {
        $presets = app(ManageReportPresets::class);
        $presets->save($this->owner, 'A', ['preset' => 'today']);
        $presets->save($this->owner, 'A', ['preset' => 'this_month']);
        expect(ReportPreset::query()->count())->toBe(1);

        foreach (range(2, 10) as $i) {
            $presets->save($this->owner, "P{$i}", []);
        }
        expect(fn () => $presets->save($this->owner, 'P11', []))->toThrow(BusinessException::class);

        $other = User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]);
        $mine = ReportPreset::query()->where('name', 'A')->sole();
        expect($presets->filtersOf($other, $mine->id))->toBeNull();
        $presets->delete($other, $mine->id);
        expect($mine->fresh())->not->toBeNull();
    });

    it('supervisor: preset di luar 31 hari dinormalkan, tab tak boleh diabaikan', function () {
        $presets = app(ManageReportPresets::class);
        $sari = $this->pos->supervisor;
        $preset = $presets->save($sari, 'Lama', ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31', 'tab' => 'stock']);
        $this->actingAs($sari);

        $page = Livewire::test(Reports::class)->callAction('applyPreset', ['preset_id' => $preset->id]);

        expect($page->get('filters.from'))->toBeGreaterThanOrEqual('2026-09-09')
            ->and($page->get('tab'))->not->toBe('stock');
    });
});

describe('tabel pendamping grafik (R9, chart.md › Aksesibilitas)', function () {
    it('tren: baris per hari dengan angka yang sama dengan grafik + total', function () {
        $range = ['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-08', 'compare' => 'none'];
        $series = app(ReportService::class)->trend(
            Carbon::parse('2026-10-08 00:00', 'Asia/Jakarta')->utc()->toImmutable(), Carbon::parse('2026-10-08 23:59:59', 'Asia/Jakarta')->utc()->toImmutable(), 'Asia/Jakarta', 'hour');
        $revenue = collect($series)->sum(fn ($r) => (float) $r['revenue']);

        Livewire::test(TrendTableWidget::class, ['pageFilters' => $range])
            ->assertSee('Data tren omzet')
            ->assertSee(Money::format(number_format($revenue, 2, '.', '')))
            ->assertSeeHtml('<tfoot>');
    });

    it('jam ramai: hanya jam bertransaksi, jam lokal outlet', function () {
        // pageSale 2026-10-08 03:00 UTC = 10.00 WIB
        Livewire::test(PeakHoursTableWidget::class, ['pageFilters' => ['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-08']])
            ->assertSee('10.00–10.59')
            ->assertDontSee('03.00–03.59');
    });

    it('tab Ringkasan & Waktu memuat tabel pendamping', function () {
        $widgets = fn (ReportTab $tab): array => (new ReflectionMethod(Reports::class, 'widgetsFor'))->invoke(new Reports, $tab);

        // Tabel tepat di bawah grafiknya
        expect($widgets(ReportTab::Summary))->toContain(TrendTableWidget::class)
            ->and(array_search(TrendTableWidget::class, $widgets(ReportTab::Summary), true))->toBe(array_search(ReportTrendChartWidget::class, $widgets(ReportTab::Summary), true) + 1)
            ->and($widgets(ReportTab::Time))->toContain(PeakHoursTableWidget::class);
    });
});
