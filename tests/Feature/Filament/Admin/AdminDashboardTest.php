<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\AppPlatform;
use App\Enums\BillingCycle;
use App\Enums\BusinessType;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\AppVersionsChartWidget;
use App\Filament\Admin\Widgets\AtRiskTenantsWidget;
use App\Filament\Admin\Widgets\ExpiringTenantsWidget;
use App\Filament\Admin\Widgets\NewLeadsWidget;
use App\Filament\Admin\Widgets\PendingInvoicesWidget;
use App\Filament\Admin\Widgets\PlatformActivityStatsWidget;
use App\Filament\Admin\Widgets\PlatformStatsWidget;
use App\Filament\Admin\Widgets\RecentTenantsWidget;
use App\Filament\Admin\Widgets\SignupsChartWidget;
use App\Filament\Admin\Widgets\SubscriptionRevenueChartWidget;
use App\Filament\Admin\Widgets\TenantsPerBusinessTypeChartWidget;
use App\Filament\Admin\Widgets\TenantsPerPlanChartWidget;
use App\Filament\Admin\Widgets\TopTenantsWidget;
use App\Filament\Admin\Widgets\TransactionsChartWidget;
use App\Models\Admin;
use App\Models\AppVersion;
use App\Models\Device;
use App\Models\SalesLead;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Admin\DashboardCache;
use App\Services\Admin\PlatformActivity;
use App\Services\Admin\PlatformMetrics;
use App\Support\Money;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Dasbor /admin (D1): pertumbuhan bisnis, pendapatan & pendaftaran 12 bulan, cache 10 menit + muat ulang. */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 03:00:00');
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    $this->plan = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000']);
});

afterEach(function () {
    Carbon::setTestNow();
    config(['pos.edition' => 'saas']);
});

function dashboardPaidInvoice(Tenant $tenant, Plan|App\Models\Plan $plan, string $paidAt): void
{
    $invoice = new SubscriptionInvoice(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_cycle' => BillingCycle::Monthly, 'amount' => '99000', 'due_at' => now()]);
    $invoice->tenant_id = $tenant->id;
    $invoice->forceFill(['number' => 'INV-'.fake()->unique()->numerify('######'), 'status' => InvoiceStatus::Paid, 'paid_at' => $paidAt])->save();
}

it('menghitung bisnis per keadaan, bisnis baru, konversi trial, dan calon pelanggan', function () {
    $paid = Tenant::factory()->create(['status' => TenantStatus::Active, 'plan_id' => $this->plan->id, 'subscription_ends_at' => now()->addMonth(), 'created_at' => now()->subDays(10)]);
    Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(3), 'created_at' => now()->subDays(11)]);
    Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(20), 'created_at' => now()->subDays(40)]);
    Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->subDay(), 'created_at' => now()->subDays(200)]);
    Tenant::factory()->create(['status' => TenantStatus::Suspended, 'created_at' => now()->subDays(300)]);
    dashboardPaidInvoice($paid, $this->plan, now()->subDays(5)->toDateTimeString());
    SalesLead::query()->forceCreate(['name' => 'Andi', 'business_name' => 'Kopi A', 'phone' => '0812', 'status' => 'new']);

    $metrics = app(PlatformMetrics::class);

    expect($metrics->tenantCounts())->toBe(['total' => 5, 'paid' => 1, 'trial' => 2, 'trial_ending' => 1, 'read_only' => 1, 'suspended' => 1])
        ->and($metrics->newTenants(30))->toBe(['current' => 2, 'previous' => 1])
        ->and($metrics->trialConversion(90))->toBe(['cohort' => 3, 'converted' => 1])
        ->and($metrics->newLeads())->toBe(1)
        ->and(array_slice($metrics->signupsPerMonth(12), -2, 2, true))->toBe(['2026-09' => 1, '2026-10' => 2]);
});

it('dasbor menampilkan KPI platform & chart 12 bulan', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active, 'plan_id' => $this->plan->id, 'subscription_ends_at' => now()->addMonth()]);
    dashboardPaidInvoice($tenant, $this->plan, '2026-10-17 05:00:00');

    Livewire::test(Dashboard::class)->assertOk()->assertSee('Muat ulang data');
    Livewire::test(PlatformStatsWidget::class)->assertOk()->assertSee('Bisnis berbayar aktif')->assertSee('Konversi trial (90 hari)');
    Livewire::test(SubscriptionRevenueChartWidget::class)->assertOk()->assertSee('Total 12 bulan Rp99.000');
    Livewire::test(SignupsChartWidget::class)->assertOk()->assertSee('1 bisnis dalam 12 bulan');
});

it('angka di-cache sampai Muat ulang data', function () {
    $metrics = app(PlatformMetrics::class);
    expect($metrics->tenantCounts()['total'])->toBe(0);

    Tenant::factory()->create();
    expect($metrics->tenantCounts()['total'])->toBe(0);

    Livewire::test(Dashboard::class)->callAction('refresh')->assertNotified('Data dasbor dihitung ulang');
    expect($metrics->tenantCounts()['total'])->toBe(1);
});

it('cache kedaluwarsa sendiri setelah 10 menit', function () {
    $metrics = app(PlatformMetrics::class);
    $metrics->tenantCounts();
    Tenant::factory()->create();

    Carbon::setTestNow(now()->addMinutes(11));
    expect($metrics->tenantCounts()['total'])->toBe(1)
        ->and(DashboardCache::generatedAt()->equalTo(now()->startOfSecond()))->toBeTrue();
});

it('widget langganan & pertumbuhan tersembunyi di edisi jual putus', function () {
    config(['pos.edition' => 'self_hosted']);

    expect(PlatformStatsWidget::canView())->toBeFalse()
        ->and(SubscriptionRevenueChartWidget::canView())->toBeFalse()
        ->and(SignupsChartWidget::canView())->toBeFalse();
});

describe('D2: komposisi & daftar kerja', function () {
    it('bisnis per paket (tanpa yang ditangguhkan) & per jenis usaha, terbanyak dulu', function () {
        Tenant::factory()->count(2)->create(['plan_id' => $this->plan->id, 'business_type' => BusinessType::Retail]);
        Tenant::factory()->create(['plan_id' => null, 'business_type' => BusinessType::Cafe]);
        Tenant::factory()->create(['plan_id' => $this->plan->id, 'status' => TenantStatus::Suspended, 'business_type' => BusinessType::Cafe]);

        $metrics = app(PlatformMetrics::class);
        expect($metrics->tenantsPerPlan())->toBe(['Basic' => 2, 'Tanpa paket' => 1])
            // Jenis usaha menghitung semua bisnis (termasuk ditangguhkan); jumlah sama → urutan bebas
            ->and($metrics->tenantsPerBusinessType())->toEqual(['Kafe / resto' => 2, 'Retail' => 2]);

        Livewire::test(TenantsPerPlanChartWidget::class)->assertOk()->assertSee('Bisnis per paket');
        Livewire::test(TenantsPerBusinessTypeChartWidget::class)->assertOk()->assertSee('Bisnis per jenis usaha');
    });

    it('segera berakhir: 14 hari ke depan, tanpa yang ditangguhkan, terdekat dulu', function () {
        $soon = Tenant::factory()->create(['name' => 'Kopi Dekat', 'status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(2)]);
        $later = Tenant::factory()->create(['name' => 'Kopi Nanti', 'status' => TenantStatus::Active, 'subscription_ends_at' => now()->addDays(10)]);
        $far = Tenant::factory()->create(['name' => 'Kopi Jauh', 'subscription_ends_at' => now()->addDays(30)]);
        $suspended = Tenant::factory()->create(['name' => 'Kopi Blokir', 'status' => TenantStatus::Suspended, 'subscription_ends_at' => now()->addDays(3)]);

        Livewire::test(ExpiringTenantsWidget::class)
            ->assertCanSeeTableRecords([$soon, $later], inOrder: true)
            ->assertCanNotSeeTableRecords([$far, $suspended])
            ->assertSee('2 hari lagi');
    });

    it('tagihan menunggu lintas bisnis, jatuh tempo terdekat dulu; pendaftaran terbaru; calon pelanggan baru terlama dulu', function () {
        $a = Tenant::factory()->create(['name' => 'Bisnis A', 'created_at' => now()->subDays(3)]);
        $b = Tenant::factory()->create(['name' => 'Bisnis B', 'created_at' => now()->subDay()]);
        foreach ([[$a, 3], [$b, 1]] as [$tenant, $days]) {
            $invoice = new SubscriptionInvoice(['plan_id' => $this->plan->id, 'plan_name' => 'Basic', 'billing_cycle' => BillingCycle::Monthly, 'amount' => '99000', 'due_at' => now()->addDays($days)]);
            $invoice->tenant_id = $tenant->id;
            $invoice->forceFill(['number' => 'INV-'.$tenant->name, 'status' => InvoiceStatus::Pending])->save();
        }
        $old = SalesLead::query()->forceCreate(['name' => 'Andi', 'business_name' => 'Lead Lama', 'phone' => '0812', 'status' => 'new', 'created_at' => now()->subDays(5)]);
        $new = SalesLead::query()->forceCreate(['name' => 'Budi', 'business_name' => 'Lead Baru', 'phone' => '0813', 'status' => 'new']);
        $done = SalesLead::query()->forceCreate(['name' => 'Cici', 'business_name' => 'Lead Selesai', 'phone' => '0814', 'status' => 'contacted']);

        Livewire::test(PendingInvoicesWidget::class)
            ->assertCanSeeTableRecords(SubscriptionInvoice::allTenants()->orderBy('due_at')->get(), inOrder: true)
            ->assertSee('Bisnis B');
        Livewire::test(RecentTenantsWidget::class)->assertCanSeeTableRecords([$b, $a], inOrder: true);
        Livewire::test(NewLeadsWidget::class)
            ->assertCanSeeTableRecords([$old, $new], inOrder: true)
            ->assertCanNotSeeTableRecords([$done]);
    });
});

/** Transaksi selesai pada waktu UTC tertentu (lewat CheckoutOrder, sama dengan kasir). */
function dashboardSale(stdClass $pos, string $utc, int $qty = 1): void
{
    Carbon::setTestNow($utc);
    TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 500000]],
    ])));
    TenantContext::forget();
}

describe('D3: aktivitas platform', function () {
    beforeEach(function () {
        $this->a = posSetup();
        $this->b = posSetup();
        // 18 Okt 00.30 WIB = 17 Okt 17.30 UTC → masuk tanggal 18 (zona bisnis)
        dashboardSale($this->a, '2026-10-17 17:30:00', 2);
        dashboardSale($this->a, '2026-10-10 05:00:00');
        dashboardSale($this->b, '2026-10-16 05:00:00');
        // 40 hari lalu: periode pembanding
        dashboardSale($this->b, '2026-09-08 05:00:00');
        Carbon::setTestNow('2026-10-18 03:00:00');
        DashboardCache::flush();
    });

    it('transaksi per hari lokal, total vs periode sebelumnya, bisnis bertransaksi', function () {
        $activity = app(PlatformActivity::class);
        $perDay = $activity->perDay(30);
        $totals = $activity->totals(30);

        expect($perDay)->toHaveCount(30)
            ->and($perDay['2026-10-18']['count'])->toBe(1)
            ->and($perDay['2026-10-17']['count'])->toBe(0)
            ->and($perDay['2026-10-16']['count'])->toBe(1)
            ->and($totals['count'])->toBe(3)
            ->and($totals['tenants'])->toBe(2)
            ->and($totals['previous_count'])->toBe(1)
            ->and($totals['gmv'])->toBe(Money::add($perDay['2026-10-18']['gmv'], $perDay['2026-10-10']['gmv'], $perDay['2026-10-16']['gmv']));
    });

    it('bisnis paling aktif berurutan & bisnis tanpa transaksi 14 hari', function () {
        $idle = Tenant::factory()->create(['name' => 'Kopi Diam', 'status' => TenantStatus::Active, 'subscription_ends_at' => now()->addMonth(), 'created_at' => now()->subDays(30)]);
        Tenant::factory()->create(['name' => 'Kopi Baru', 'status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(10), 'created_at' => now()->subDays(3)]);
        Tenant::factory()->create(['name' => 'Kopi Blokir', 'status' => TenantStatus::Suspended, 'created_at' => now()->subDays(30)]);
        $activity = app(PlatformActivity::class);

        expect(array_column($activity->topTenants(30), 'tenant_id'))->toBe([$this->a->tenantId, $this->b->tenantId]);

        $risk = $activity->atRiskTenants(14);
        expect(array_column($risk['rows'], 'name'))->toBe(['Kopi Diam'])
            ->and($risk['rows'][0]['last_order_at'])->toBeNull()
            ->and($risk['count'])->toBe(1);
    });

    it('perangkat aktif & versi Android di bawah minimum', function () {
        AppVersion::query()->create(['platform' => AppPlatform::Android, 'min_version' => '1.2.0', 'latest_version' => '1.3.0']);
        Device::allTenants()->update(['platform' => AppPlatform::Android, 'app_version' => '1.1.0', 'last_seen_at' => now()->subDay()]);
        TenantContext::run($this->a->tenantId, fn () => Device::factory()->forOutlet($this->a->outlet)->create(['platform' => AppPlatform::Android, 'app_version' => '1.3.0', 'last_seen_at' => now()]));
        TenantContext::forget();

        $versions = app(PlatformActivity::class)->androidVersions();
        Livewire::test(AppVersionsChartWidget::class)->assertOk()->assertSee('2 perangkat wajib update');
        expect(array_keys($versions['versions']))->toBe(['1.3.0', '1.1.0'])
            ->and($versions['below_min'])->toBe(2)
            ->and(app(PlatformActivity::class)->devices()['active'])->toBe(3);
    });

    it('widget aktivitas tampil', function () {
        Livewire::test(PlatformActivityStatsWidget::class)->assertOk()->assertSee('Aktivitas platform (30 hari)')->assertSee('Bisnis bertransaksi');
        Livewire::test(TransactionsChartWidget::class)->assertOk()->assertSee('3 transaksi');
        Livewire::test(TopTenantsWidget::class)->assertOk()->assertSee('Bisnis paling aktif');
        Livewire::test(AtRiskTenantsWidget::class)->assertOk()->assertSee('Tidak bertransaksi 14 hari');
        expect(AppVersionsChartWidget::canView())->toBeTrue();

        // Tanpa perangkat Android aktif: chart versi (hanya sumbu kosong) disembunyikan
        Device::allTenants()->update(['revoked_at' => now()]);
        DashboardCache::flush();
        expect(AppVersionsChartWidget::canView())->toBeFalse();
    });
});
