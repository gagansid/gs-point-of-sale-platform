<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\PlatformStatsWidget;
use App\Filament\Admin\Widgets\SignupsChartWidget;
use App\Filament\Admin\Widgets\SubscriptionRevenueChartWidget;
use App\Models\Admin;
use App\Models\SalesLead;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Admin\DashboardCache;
use App\Services\Admin\PlatformMetrics;
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
