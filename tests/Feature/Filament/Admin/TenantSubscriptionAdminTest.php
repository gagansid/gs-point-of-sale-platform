<?php

declare(strict_types=1);

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvoiceCreated;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'max_outlets' => 3, 'features' => array_keys(Feature::options()), 'sort_order' => 2]);
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

describe('buat tagihan dari detail bisnis', function () {
    beforeEach(function () {
        Notification::fake();
        $this->tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $this->owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create(['email' => 'owner@kopi.test']));
        $this->special = app(SavePlan::class)->handle(null, ['code' => 'khusus', 'name' => 'Khusus Waralaba', 'price_monthly' => '500000', 'price_yearly' => '5000000', 'is_public' => false, 'sort_order' => 3]);
        TenantContext::forget();
    });

    it('membuat tagihan paket khusus (non-publik), mencatat admin, dan mengirim email ke owner', function () {
        $admin = auth('admin')->user();

        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->callAction('createInvoice', ['plan_id' => $this->special->id, 'billing_cycle' => 'yearly', 'notify_owners' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $invoice = SubscriptionInvoice::allTenants()->where('tenant_id', $this->tenant->id)->sole();
        expect($invoice->plan_name)->toBe('Khusus Waralaba')
            ->and($invoice->kind)->toBe(InvoiceKind::Renewal)
            ->and($invoice->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and($invoice->amount)->toBe('5000000.00')
            ->and($invoice->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->created_by_admin_id)->toBe($admin->id);

        Notification::assertSentTo($this->owner, InvoiceCreated::class, fn (InvoiceCreated $n): bool => $n->number === $invoice->number && $n->amount === 'Rp5.000.000');
    });

    it('tanpa email bila opsi kirim email dimatikan', function () {
        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly', 'notify_owners' => false])
            ->assertHasNoActionErrors();

        expect(SubscriptionInvoice::allTenants()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
        Notification::assertNothingSent();
    });

    it('memvalidasi paket & siklus wajib', function (array $data, string $field) {
        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly', ...$data])
            ->assertHasActionErrors([$field]);

        expect(SubscriptionInvoice::allTenants()->count())->toBe(0);
    })->with([
        'paket kosong' => [['plan_id' => null], 'plan_id'],
        'siklus kosong' => [['billing_cycle' => null], 'billing_cycle'],
    ]);

    it('menolak paket nonaktif (tidak ada di pilihan, dan ditolak server)', function () {
        $this->special->forceFill(['is_active' => false])->save();

        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->callAction('createInvoice', ['plan_id' => $this->special->id, 'billing_cycle' => 'monthly'])
            ->assertHasActionErrors(['plan_id']);

        expect(fn () => app(CreateInvoice::class)->handle($this->tenant, $this->special, BillingCycle::Monthly, auth('admin')->user()))
            ->toThrow(BusinessException::class, 'Paket tidak tersedia')
            ->and(SubscriptionInvoice::allTenants()->count())->toBe(0);
    });

    it('paket khusus tetap tertutup untuk owner (tanpa admin)', function () {
        expect(fn () => TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant, $this->special, BillingCycle::Monthly)))
            ->toThrow(BusinessException::class, 'Paket tidak tersedia');
    });

    it('membuat dua kali tetap menyisakan satu tagihan menunggu (yang lama dibatalkan)', function () {
        $page = Livewire::test(ViewTenant::class, ['record' => $this->tenant->id]);
        $page->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly', 'notify_owners' => false]);
        $page->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly', 'notify_owners' => false]);

        $invoices = SubscriptionInvoice::allTenants()->where('tenant_id', $this->tenant->id)->get();
        expect($invoices)->toHaveCount(2)
            ->and($invoices->where('status', InvoiceStatus::Pending))->toHaveCount(1);
    });

    it('tagihan hanya untuk bisnis yang dipilih (isolasi tenant)', function () {
        $other = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $theirs = subscriptionAdminInvoice($other, $this->pro);

        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly', 'notify_owners' => false]);

        expect($theirs->refresh()->status)->toBe(InvoiceStatus::Pending)
            ->and(SubscriptionInvoice::allTenants()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    });

    it('aksi tersembunyi di edisi jual putus', function () {
        config(['pos.edition' => 'self_hosted']);

        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->assertActionHidden('createInvoice');
    });

    it('aksi tersembunyi bila belum ada paket aktif', function () {
        Plan::query()->update(['is_active' => false]);

        Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
            ->assertActionHidden('createInvoice');
    });

    it('pengguna tenant (owner) tidak bisa membuka panel admin', function () {
        auth('admin')->logout();
        $this->actingAs($this->owner);

        $this->get(TenantResource::getUrl('view', ['record' => $this->tenant]))->assertForbidden();
    });
});
