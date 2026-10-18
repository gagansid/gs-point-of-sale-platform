<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Filament\Admin\Widgets\SubscriptionStatsWidget;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionMetrics;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Ringkasan pendapatan langganan di Dasbor /admin (ADR 0024): lunas per bulan (zona waktu bisnis), tagihan menunggu,
 * estimasi MRR, bisnis berlangganan per paket.
 */
beforeEach(function () {
    // 18 Okt 2026 10.00 WIB
    Carbon::setTestNow('2026-10-18 03:00:00');
    TenantContext::forget();
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'sort_order' => 1]);
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1200000', 'sort_order' => 2]);
});

afterEach(function () {
    Carbon::setTestNow();
    config(['pos.edition' => 'saas']);
});

function metricsInvoice(Plan $plan, string $amount, InvoiceStatus $status, ?string $paidAt = null): SubscriptionInvoice
{
    $tenant = Tenant::factory()->create();
    $invoice = new SubscriptionInvoice(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_cycle' => BillingCycle::Monthly, 'amount' => $amount, 'due_at' => now()->addDays(3)]);
    $invoice->tenant_id = $tenant->id;
    $invoice->forceFill(['number' => 'INV-'.fake()->unique()->numerify('######'), 'status' => $status, 'paid_at' => $paidAt])->save();

    return $invoice;
}

function metricsTenant(TenantStatus $status, ?Plan $plan, ?BillingCycle $cycle, ?string $endsAt): Tenant
{
    return Tenant::factory()->create(['status' => $status, 'plan_id' => $plan?->id, 'billing_cycle' => $cycle, 'subscription_ends_at' => $endsAt]);
}

it('menjumlah tagihan lunas per bulan menurut zona waktu bisnis', function () {
    // 1 Okt 00.30 WIB = 30 Sep 17.30 UTC → masuk Oktober
    metricsInvoice($this->basic, '99000', InvoiceStatus::Paid, '2026-09-30 17:30:00');
    metricsInvoice($this->pro, '199000', InvoiceStatus::Paid, '2026-10-17 05:00:00');
    // 30 Sep 23.59 WIB → September
    metricsInvoice($this->basic, '50000', InvoiceStatus::Paid, '2026-09-30 16:59:00');
    // Tidak dihitung: menunggu, dibatalkan, lebih dari 6 bulan lalu
    metricsInvoice($this->basic, '99000', InvoiceStatus::Pending);
    metricsInvoice($this->basic, '99000', InvoiceStatus::Cancelled);
    metricsInvoice($this->basic, '70000', InvoiceStatus::Paid, '2026-04-10 05:00:00');

    $paid = app(SubscriptionMetrics::class)->paidPerMonth(6);

    expect(array_keys($paid))->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
        ->and($paid['2026-10'])->toBe('298000.00')
        ->and($paid['2026-09'])->toBe('50000.00')
        ->and($paid['2026-05'])->toBe('0.00');
});

it('menghitung tagihan menunggu lintas bisnis', function () {
    metricsInvoice($this->basic, '99000', InvoiceStatus::Pending);
    metricsInvoice($this->pro, '199000', InvoiceStatus::Pending);
    metricsInvoice($this->pro, '199000', InvoiceStatus::Expired);

    expect(app(SubscriptionMetrics::class)->pending())->toBe(['count' => 2, 'amount' => '298000.00']);
});

it('estimasi MRR & bisnis per paket hanya dari langganan berbayar yang berjalan', function () {
    $future = now()->addDays(10)->toDateTimeString();
    metricsTenant(TenantStatus::Active, $this->basic, BillingCycle::Monthly, $future);
    metricsTenant(TenantStatus::Active, $this->basic, BillingCycle::Monthly, $future);
    // Tahunan 1.200.000 ÷ 12 = 100.000
    metricsTenant(TenantStatus::Active, $this->pro, BillingCycle::Yearly, $future);
    // Tidak dihitung: trial, habis, ditangguhkan, tanpa paket
    metricsTenant(TenantStatus::Trial, $this->pro, BillingCycle::Monthly, $future);
    metricsTenant(TenantStatus::Active, $this->pro, BillingCycle::Monthly, now()->subDay()->toDateTimeString());
    metricsTenant(TenantStatus::Suspended, $this->pro, BillingCycle::Monthly, $future);
    metricsTenant(TenantStatus::Active, null, null, null);

    $metrics = app(SubscriptionMetrics::class);
    $active = $metrics->activeSubscriptions();

    expect($active['mrr'])->toBe('298000.00')
        ->and($active['count'])->toBe(3)
        ->and($active['plans'])->toBe([['name' => 'Basic', 'count' => 2], ['name' => 'Pro', 'count' => 1]])
        ->and($metrics->trialCount())->toBe(1);
});

it('kosong: semua nol tanpa error', function () {
    $metrics = app(SubscriptionMetrics::class);

    expect($metrics->pending())->toBe(['count' => 0, 'amount' => '0.00'])
        ->and($metrics->activeSubscriptions())->toBe(['mrr' => '0.00', 'count' => 0, 'plans' => []])
        ->and(array_unique(array_values($metrics->paidPerMonth())))->toBe(['0.00']);
});

describe('widget Dasbor /admin', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    });

    it('menampilkan ringkasan langganan', function () {
        metricsInvoice($this->pro, '199000', InvoiceStatus::Paid, '2026-10-17 05:00:00');
        metricsInvoice($this->basic, '99000', InvoiceStatus::Paid, '2026-09-10 05:00:00');
        metricsInvoice($this->basic, '99000', InvoiceStatus::Pending);
        metricsTenant(TenantStatus::Active, $this->pro, BillingCycle::Monthly, now()->addDays(10)->toDateTimeString());

        Livewire::test(SubscriptionStatsWidget::class)
            ->assertOk()
            ->assertSee('Lunas bulan ini')
            ->assertSee('Rp199.000')
            ->assertSee('Naik 101,0%')
            ->assertSee('Tagihan menunggu')
            ->assertSee('Rp99.000')
            ->assertSee('Estimasi MRR')
            ->assertSee('Pro 1');
    });

    it('tersembunyi di edisi jual putus', function () {
        config(['pos.edition' => 'self_hosted']);

        expect(SubscriptionStatsWidget::canView())->toBeFalse();
    });
});
