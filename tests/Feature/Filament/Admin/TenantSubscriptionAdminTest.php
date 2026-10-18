<?php

declare(strict_types=1);

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Langganan tenant di panel /admin (ADR 0024): paket & perubahan terjadwal & tagihan terbaru di detail bisnis,
 * kolom/filter paket di daftar bisnis, filter bisnis di daftar tagihan.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 03:00:00');
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'max_outlets' => 1, 'features' => ['member'], 'sort_order' => 1]);
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'max_outlets' => 3, 'features' => array_keys(Plan::FEATURES), 'sort_order' => 2]);
});

afterEach(function () {
    Carbon::setTestNow();
    config(['pos.edition' => 'saas']);
});

function subscriptionAdminInvoice(Tenant $tenant, Plan $plan): SubscriptionInvoice
{
    return TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant->refresh(), $plan, BillingCycle::Monthly));
}

describe('detail bisnis', function () {
    it('menampilkan paket, siklus, batas outlet, dan perubahan terjadwal', function () {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Active, 'subscription_ends_at' => now()->addDays(20),
            'plan_id' => $this->pro->id, 'billing_cycle' => BillingCycle::Monthly, 'max_outlets' => 3,
            'next_plan_id' => $this->basic->id, 'next_billing_cycle' => BillingCycle::Yearly, 'plan_changes_at' => now()->addDays(20),
        ]);

        Livewire::test(ViewTenant::class, ['record' => $tenant->id])
            ->assertOk()
            ->assertSee('Langganan')
            ->assertSee('Pro')
            ->assertSee('Bulanan')
            ->assertSee('Basic · Tahunan mulai 7 Nov 2026');
    });

    it('menampilkan "Tanpa paket" untuk bisnis tanpa paket', function () {
        $tenant = Tenant::factory()->create(['plan_id' => null]);

        Livewire::test(ViewTenant::class, ['record' => $tenant->id])
            ->assertOk()
            ->assertSee('Tanpa paket (semua fitur)')
            ->assertSee('Belum ada tagihan');
    });

    it('menampilkan tagihan terbaru milik bisnis itu saja (isolasi tenant)', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $other = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $mine = subscriptionAdminInvoice($tenant, $this->basic);
        $theirs = subscriptionAdminInvoice($other, $this->pro);

        Livewire::test(ViewTenant::class, ['record' => $tenant->id])
            ->assertOk()
            ->assertSee($mine->number)
            ->assertSee('Menunggu bayar')
            ->assertDontSee($theirs->number);
    });

    it('menyembunyikan section langganan di edisi jual putus', function () {
        config(['pos.edition' => 'self_hosted']);
        $tenant = Tenant::factory()->create(['plan_id' => $this->pro->id]);

        Livewire::test(ViewTenant::class, ['record' => $tenant->id])
            ->assertOk()
            ->assertDontSee('Perubahan terjadwal');
    });
});

describe('daftar bisnis', function () {
    it('menampilkan kolom paket dan memfilter per paket atau tanpa paket', function () {
        $pro = Tenant::factory()->create(['plan_id' => $this->pro->id, 'billing_cycle' => BillingCycle::Yearly]);
        $basic = Tenant::factory()->create(['plan_id' => $this->basic->id, 'billing_cycle' => BillingCycle::Monthly]);
        $none = Tenant::factory()->create(['plan_id' => null]);

        Livewire::test(ListTenants::class)
            ->assertTableColumnStateSet('plan.name', 'Pro', $pro)
            ->filterTable('plan_id', $this->pro->id)
            ->assertCanSeeTableRecords([$pro])
            ->assertCanNotSeeTableRecords([$basic, $none])
            ->filterTable('plan_id', 'none')
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$pro, $basic]);
    });
});

describe('daftar tagihan', function () {
    it('memfilter tagihan per bisnis', function () {
        $a = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $b = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $invA = subscriptionAdminInvoice($a, $this->basic);
        $invB = subscriptionAdminInvoice($b, $this->pro);

        Livewire::test(ListSubscriptionInvoices::class)
            ->assertCanSeeTableRecords([$invA, $invB])
            ->filterTable('tenant_id', $a->id)
            ->assertCanSeeTableRecords([$invA])
            ->assertCanNotSeeTableRecords([$invB]);
    });
});
