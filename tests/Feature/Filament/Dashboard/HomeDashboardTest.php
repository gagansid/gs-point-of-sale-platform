<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Filament\Dashboard\Pages\Home;
use App\Filament\Dashboard\Widgets\AuditWidget;
use App\Filament\Dashboard\Widgets\DailyRevenueWidget;
use App\Filament\Dashboard\Widgets\OrderTypesWidget;
use App\Filament\Dashboard\Widgets\OutletComparisonWidget;
use App\Filament\Dashboard\Widgets\PaymentMethodsChartWidget;
use App\Filament\Dashboard\Widgets\PeakHoursChartWidget;
use App\Filament\Dashboard\Widgets\RevenueChartWidget;
use App\Filament\Dashboard\Widgets\SalesStatsWidget;
use App\Filament\Dashboard\Widgets\TopProductsWidget;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/** Checkout pada waktu UTC tertentu (zona outlet Asia/Jakarta). */
function homeSale(stdClass $pos, string $utc, string $type = 'takeaway', string $method = 'cash'): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => $type,
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        // 1 Croissant = 28.000 setelah service, pajak & pembulatan
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods[$method]->id, 'amount' => $method === 'cash' ? 50000 : 28000]],
    ]))['order']);
}

/** State DatePicker Filament berisi jam ("2026-10-08 00:00:00"); yang dipakai hanya tanggalnya. */
function dateIs(string $date): Closure
{
    return fn (?string $value): bool => substr((string) $value, 0, 10) === $date;
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

    homeSale($this->pos, '2026-10-07 03:00:00');                       // kemarin 10.00 WIB
    homeSale($this->pos, '2026-10-08 02:00:00', 'dine_in');            // hari ini 09.00 WIB
    homeSale($this->pos, '2026-10-08 05:00:00', 'takeaway', 'qris');   // hari ini 12.00 WIB
    $voided = homeSale($this->pos, '2026-10-08 06:00:00');
    TenantContext::run($this->pos->tenantId, fn () => app(VoidOrder::class)->handle($this->pos->supervisor, $voided, 'Pelanggan batal', null));

    Carbon::setTestNow('2026-10-08 10:00:00');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('beranda tampil dengan filter dan semua widget ringkasan', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Bandingkan dengan')
        ->assertSee('Rp56.000')            // omzet hari ini
        ->assertSee('Naik 100,0% dari periode sebelumnya')
        ->assertSee('Komposisi metode bayar')
        ->assertSee('Jam ramai')
        ->assertSee('Riwayat pendapatan')
        ->assertSee('Audit &amp; kontrol', false)
        ->assertSee('Pelanggan batal');
});

it('filter default hari ini; ganti preset mengisi tanggal otomatis', function () {
    Livewire::test(Home::class)
        ->assertSet('filters.preset', 'today')
        ->assertSet('filters.from', dateIs('2026-10-08'))
        ->assertSet('filters.compare', 'previous_period')
        ->set('filters.preset', 'last_7_days')
        ->assertSet('filters.from', dateIs('2026-10-02'))
        ->assertSet('filters.to', dateIs('2026-10-08'));
});

it('ubah tanggal manual = preset kustom; tanggal masa depan & rentang > 366 hari dibatasi', function () {
    Livewire::test(Home::class)
        ->set('filters.from', '2026-10-07')
        ->assertSet('filters.preset', 'custom');

    Livewire::withQueryParams(['filters' => ['preset' => 'custom', 'from' => '2024-01-01', 'to' => '2027-01-01']])
        ->test(Home::class)
        ->assertSet('filters.to', dateIs('2026-10-08'))
        ->assertSet('filters.from', dateIs('2025-10-08'));
});

it('widget mengikuti filter halaman (pageFilters)', function () {
    $today = ['preset' => 'today', 'compare' => 'previous_period'];
    $range = ['preset' => 'custom', 'from' => '2026-10-07', 'to' => '2026-10-08', 'compare' => 'none'];

    Livewire::test(SalesStatsWidget::class, ['pageFilters' => $today])->assertSee('Rp56.000')->assertSee('1 transaksi');
    Livewire::test(SalesStatsWidget::class, ['pageFilters' => $range])->assertSee('Rp84.000');
    Livewire::test(SalesStatsWidget::class, ['pageFilters' => [...$today, 'order_type' => 'dine_in']])->assertSee('Rp28.000');
    Livewire::test(SalesStatsWidget::class, ['pageFilters' => [...$today, 'payment_method_ids' => [$this->pos->methods['qris']->id]]])->assertSee('Rp28.000');

    Livewire::test(RevenueChartWidget::class, ['pageFilters' => $range])->assertSee('Tren omzet harian');
    Livewire::test(RevenueChartWidget::class, ['pageFilters' => $today])->assertSee('Tren omzet per jam');
    Livewire::test(PeakHoursChartWidget::class, ['pageFilters' => $today])->assertSee('Tersibuk pukul 09.00');
    Livewire::test(PaymentMethodsChartWidget::class, ['pageFilters' => $today])->assertSee('terbanyak');
    Livewire::test(OrderTypesWidget::class, ['pageFilters' => $today])->assertSee('Makan di tempat')->assertSee('50%');
    Livewire::test(AuditWidget::class, ['pageFilters' => $today])->assertSee('Pelanggan batal')->assertSee('Sari');
});

it('produk: tab terlaris & kurang laku (termasuk yang belum terjual)', function () {
    Livewire::test(TopProductsWidget::class, ['pageFilters' => ['preset' => 'today']])
        ->assertSee('Croissant')
        ->assertDontSee('Es Kopi Susu')
        ->call('setMode', 'slow')
        ->assertSee('Produk paling tidak laku')
        ->assertSee('Es Kopi Susu');
});

it('riwayat harian: baris total & urutan kolom', function () {
    Livewire::test(DailyRevenueWidget::class, ['pageFilters' => ['preset' => 'custom', 'from' => '2026-10-07', 'to' => '2026-10-08']])
        ->assertSeeInOrder(['Kam, 8 Okt 2026', 'Rab, 7 Okt 2026', 'Total', 'Rp84.000'])
        ->call('sortBy', 'date')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Rab, 7 Okt 2026', 'Kam, 8 Okt 2026'])
        ->call('sortBy', 'bukan_kolom')
        ->assertSet('sortColumn', 'date');
});

it('ganti outlet dari filter = ganti pilihan sidebar; outlet tenant lain ditolak', function () {
    $second = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create(['tenant_id' => $this->pos->tenantId, 'name' => 'Cabang Dua']));
    $foreign = posSetup()->outlet;
    TenantContext::set($this->pos->tenantId);

    Livewire::test(Home::class)
        ->assertSet('filters.outlet', 'all')
        ->set('filters.outlet', $second->id)
        ->assertDispatched('pos-outlet-changed', label: 'Cabang Dua');

    expect(CurrentOutlet::sessionChoice())->toBe($second->id);

    Livewire::test(Home::class)->set('filters.outlet', $foreign->id)->assertNotFound();
});

it('perbandingan outlet hanya untuk user dengan > 1 outlet', function () {
    expect(OutletComparisonWidget::canView())->toBeFalse();

    TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create(['tenant_id' => $this->pos->tenantId]));
    $this->owner->forgetOutletIds();

    expect(OutletComparisonWidget::canView())->toBeTrue();
});

it('tanpa report.view: filter & widget ringkasan tidak tampil', function () {
    foreach ([SalesStatsWidget::class, RevenueChartWidget::class, AuditWidget::class, DailyRevenueWidget::class] as $widget) {
        $this->actingAs($this->pos->supervisor);
        expect($widget::canView())->toBeFalse();
    }
});

it('isolasi tenant: angka beranda tidak memuat transaksi tenant lain', function () {
    $other = posSetup();
    homeSale($other, '2026-10-08 04:00:00');
    Carbon::setTestNow('2026-10-08 10:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    Livewire::test(SalesStatsWidget::class, ['pageFilters' => ['preset' => 'today']])->assertSee('Rp56.000');
    Livewire::test(AuditWidget::class, ['pageFilters' => ['preset' => 'today']])->assertSee('Pelanggan batal');
});

it('export Excel mengikuti periode & filter beranda', function () {
    Excel::fake();

    Livewire::test(Home::class)->callAction('export');

    Excel::assertDownloaded('laporan-penjualan-2026-10-08-2026-10-08.xlsx');
});
