<?php

declare(strict_types=1);

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Admin;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Celah G8 (COVERAGE-MATRIX): paket langganan (PlanResource), batalkan tagihan (CancelInvoice) dan atur paket tenant
 * (TenantPlanAction) dari panel /admin (ADR 0024, Q108–Q110).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00');
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'limits' => ['outlets' => 1], 'features' => ['member'], 'sort_order' => 1]);
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'limits' => ['outlets' => 3], 'features' => array_keys(Feature::options()), 'sort_order' => 2]);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function planAdminForm(array $override = []): array
{
    return [
        'name' => 'Bisnis', 'code' => 'bisnis', 'description' => 'Untuk kafe 2 cabang',
        'price_monthly' => '149000', 'price_yearly' => '1490000', 'limits' => ['outlets' => 2], 'sort_order' => 3,
        'features' => ['member', 'stock'], 'is_active' => true, 'is_public' => true,
        ...$override,
    ];
}

/** Tagihan menunggu milik tenant trial (dibuat seperti dari halaman Langganan owner). */
function planAdminInvoice(Tenant $tenant, Plan $plan): SubscriptionInvoice
{
    return TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant->refresh(), $plan, BillingCycle::Monthly));
}

describe('PlanResource (ManagePlans)', function () {
    it('menampilkan daftar paket berurutan', function () {
        $hidden = app(SavePlan::class)->handle(null, ['code' => 'khusus', 'name' => 'Khusus', 'price_monthly' => '1', 'price_yearly' => '1', 'is_public' => false, 'sort_order' => 0]);

        Livewire::test(ManagePlans::class)
            ->assertCanSeeTableRecords([$hidden, $this->basic, $this->pro], inOrder: true)
            ->assertTableColumnFormattedStateSet('is_public', 'Khusus', $hidden)
            ->assertTableColumnFormattedStateSet('is_public', 'Publik', $this->basic)
            ->assertTableColumnFormattedStateSet('is_active', 'Aktif', $this->basic)
            ->assertTableColumnStateSet('features', '1 dari '.count(Feature::options()), $this->basic);
    });

    it('membuat paket baru dengan harga, batas outlet, dan fitur', function () {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('create')->table(), planAdminForm())
            ->assertHasNoActionErrors();

        $plan = Plan::query()->where('code', 'bisnis')->sole();
        expect($plan->name)->toBe('Bisnis')
            ->and($plan->price_monthly)->toBe('149000.00')
            ->and($plan->price_yearly)->toBe('1490000.00')
            ->and($plan->limit('outlets'))->toBe(2)
            ->and($plan->features)->toBe(['member', 'stock'])
            ->and($plan->is_public)->toBeTrue();
    });

    it('mengubah harga & fitur paket tanpa mengubah tagihan yang sudah dibuat (snapshot)', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $invoice = planAdminInvoice($tenant, $this->basic);

        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('edit')->table($this->basic), planAdminForm(['name' => 'Basic Plus', 'code' => 'basic', 'price_monthly' => '109000', 'features' => ['member', 'promotions']]))
            ->assertHasNoActionErrors();

        expect($this->basic->refresh()->name)->toBe('Basic Plus')
            ->and($this->basic->price_monthly)->toBe('109000.00')
            ->and($this->basic->features)->toBe(['member', 'promotions'])
            ->and($invoice->refresh()->amount)->toBe('99000.00')
            ->and($invoice->plan_name)->toBe('Basic');
    });

    it('memvalidasi form paket', function (array $override, string $field) {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('create')->table(), planAdminForm($override))
            ->assertHasActionErrors([$field]);

        expect(Plan::query()->count())->toBe(2);
    })->with([
        'nama kosong' => [['name' => ''], 'name'],
        'kode kosong' => [['code' => ''], 'code'],
        'harga bulanan negatif' => [['price_monthly' => '-1'], 'price_monthly'],
        'maks. outlet 0' => [['limits' => ['outlets' => 0]], 'limits.outlets'],
    ]);

    // Aturan kode (format & unik) dicek SavePlan di server: error tampil di field "code"
    it('menolak kode paket berformat salah atau sudah dipakai', function (string $code) {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('create')->table(), planAdminForm(['code' => $code]))
            ->assertHasErrors(['code']);

        expect(Plan::query()->count())->toBe(2);
    })->with(['huruf besar & spasi' => 'Paket Baru', 'sudah dipakai' => 'pro']);

    it('akun tenant tidak bisa membuka halaman paket', function () {
        $owner = User::factory()->owner()->create();

        auth('admin')->logout();
        $this->actingAs($owner, 'web')->get('/admin/plans')->assertRedirect('/admin/login');
    });
});

describe('SubscriptionInvoiceResource: batalkan', function () {
    it('membatalkan tagihan menunggu', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $invoice = planAdminInvoice($tenant, $this->pro);

        Livewire::test(ListSubscriptionInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->callAction(TestAction::make('cancel')->table($invoice))
            ->assertNotified('Tagihan dibatalkan');

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($tenant->refresh()->plan_id)->toBeNull();
    });

    it('tagihan lunas tidak bisa dibatalkan (aksi tersembunyi & Action menolak)', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $invoice = app(MarkInvoicePaid::class)->handle(planAdminInvoice($tenant, $this->pro), 'manual');

        Livewire::test(ListSubscriptionInvoices::class)
            ->assertActionHidden(TestAction::make('cancel')->table($invoice));

        expect(fn () => app(CancelInvoice::class)->handle($invoice))->toThrow(BusinessException::class, 'Tagihan lunas tidak bisa dibatalkan')
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });
});

describe('TenantPlanAction (Atur paket)', function () {
    it('mengatur paket & siklus tenant, batas outlet ikut paket, jadwal ganti paket dibatalkan', function () {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Active, 'subscription_ends_at' => now()->addDays(15),
            'plan_id' => $this->pro->id, 'billing_cycle' => BillingCycle::Monthly,
        ]);
        $tenant->forceFill(['next_plan_id' => $this->basic->id, 'next_billing_cycle' => BillingCycle::Monthly, 'plan_changes_at' => now()->addDays(15)])->save();

        Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
            ->callAction('plan', ['plan_id' => $this->basic->id, 'billing_cycle' => 'yearly'])
            ->assertNotified('Paket disimpan');

        $tenant->refresh();
        expect($tenant->plan_id)->toBe($this->basic->id)
            ->and($tenant->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and($tenant->outletLimit())->toBe(1)
            ->and($tenant->next_plan_id)->toBeNull()
            ->and($tenant->next_billing_cycle)->toBeNull()
            ->and($tenant->plan_changes_at)->toBeNull()
            // Masa aktif tidak berubah: 16 Okt + 15 hari = 31 Okt
            ->and($tenant->subscription_ends_at->toDateString())->toBe('2026-10-31');
    });

    it('paket dikosongkan = semua fitur, siklus dihapus', function () {
        $tenant = Tenant::factory()->create(['plan_id' => $this->basic->id, 'billing_cycle' => BillingCycle::Monthly]);

        Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
            ->callAction('plan', ['plan_id' => null, 'billing_cycle' => 'monthly']);

        $tenant->refresh();
        expect($tenant->plan_id)->toBeNull()->and($tenant->billing_cycle)->toBeNull();
    });

    it('aksi tersembunyi di edisi jual putus', function () {
        config(['pos.edition' => 'self_hosted']);
        $tenant = Tenant::factory()->create();

        Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
            ->assertActionHidden('plan');

        config(['pos.edition' => 'saas']);
    });
});
