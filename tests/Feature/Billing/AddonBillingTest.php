<?php

declare(strict_types=1);

use App\Actions\Billing\ApplyScheduledPlanChanges;
use App\Actions\Billing\CreateAddonInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\SaveAddon;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SetTenantAddons;
use App\Actions\Billing\StopTenantAddon;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceItem;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Notifications\InvoiceCreated;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Tagihan dengan add-on (ADR 0025 C2): baris tagihan, perpanjangan memuat add-on, tambah add-on prorata, berhenti di
 * akhir masa aktif. Masa aktif contoh: 16 Okt → 31 Okt (sisa 15 dari 31 hari).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00');
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'limits' => ['outlets' => 1], 'features' => ['member']]);
    $this->bisnis = app(SavePlan::class)->handle(null, ['code' => 'bisnis', 'name' => 'Bisnis', 'price_monthly' => '399000', 'price_yearly' => '3990000', 'limits' => ['outlets' => 3], 'features' => ['member', 'self_order']]);
    $this->extraOutlet = app(SaveAddon::class)->handle(null, ['code' => 'extra-outlet', 'name' => 'Outlet tambahan', 'type' => 'quota', 'quota_key' => 'outlets', 'quota_amount' => 1, 'price_monthly' => '99000', 'price_yearly' => '990000']);
    $this->selfOrder = app(SaveAddon::class)->handle(null, ['code' => 'self-order', 'name' => 'Self-order QR', 'type' => 'feature', 'feature_key' => 'self_order', 'price_monthly' => '50000', 'price_yearly' => '500000']);
    $this->vip = app(SaveAddon::class)->handle(null, ['code' => 'vip', 'name' => 'Karyawan VIP', 'type' => 'quota', 'quota_key' => 'users', 'quota_amount' => 10, 'price_monthly' => '10000', 'price_yearly' => '100000', 'is_public' => false]);
});

afterEach(fn () => Carbon::setTestNow());

function paidBasic(object $test): void
{
    $test->tenant->forceFill([
        'status' => TenantStatus::Active, 'plan_id' => $test->basic->id, 'billing_cycle' => BillingCycle::Monthly,
        'limit_overrides' => null, 'subscription_ends_at' => now()->addDays(15),
    ])->save();
    freshAuth();
}

function addonInvoice(object $test, $addon, int $qty = 1, ?Admin $admin = null): SubscriptionInvoice
{
    return TenantContext::run($test->tenant->id, fn () => app(CreateAddonInvoice::class)->handle($test->tenant->refresh(), $addon, $qty, $admin));
}

describe('perpanjangan', function () {
    it('memuat paket + add-on aktif; add-on berhenti & fitur yang sudah di paket tujuan tidak ikut', function () {
        paidBasic($this);
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 2, $this->selfOrder->id => 1]);

        $invoice = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->basic, BillingCycle::Monthly));
        expect($invoice->kind)->toBe(InvoiceKind::Renewal)
            ->and($invoice->amount)->toBe('347000.00')
            ->and($invoice->items->map(fn ($i) => [$i->type, $i->name, $i->quantity, $i->amount])->all())->toBe([
                ['plan', 'Paket Basic', 1, '99000.00'],
                ['addon', 'Outlet tambahan', 2, '198000.00'],
                ['addon', 'Self-order QR', 1, '50000.00'],
            ])
            ->and($invoice->description())->toBe('Perpanjangan Basic · Bulanan + Outlet tambahan ×2, Self-order QR');

        // Self-order berhenti; paket Bisnis tahunan sudah memuat self-order
        $row = TenantAddon::forTenant($this->tenant->id)->where('addon_id', $this->selfOrder->id)->sole();
        app(StopTenantAddon::class)->handle($row, true);
        $yearly = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->bisnis, BillingCycle::Yearly));

        expect($yearly->items->pluck('name')->all())->toBe(['Paket Bisnis', 'Outlet tambahan'])
            ->and($yearly->amount)->toBe('5970000.00');
    });

    it('lunas paket yang memuat fitur add-on: add-on itu berhenti di akhir masa aktif', function () {
        paidBasic($this);
        app(SetTenantAddons::class)->handle($this->tenant, [$this->selfOrder->id => 1]);
        $this->tenant->forceFill(['billing_cycle' => BillingCycle::Monthly])->save();
        // Upgrade ke Bisnis (siklus sama, lebih mahal) = paket langsung berganti
        $upgrade = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->bisnis, BillingCycle::Monthly));
        expect($upgrade->kind)->toBe(InvoiceKind::Upgrade);

        app(MarkInvoicePaid::class)->handle($upgrade, 'manual');

        $row = TenantAddon::forTenant($this->tenant->id)->sole();
        expect($row->status)->toBe(TenantAddon::CANCELLING)
            ->and($row->cancel_at->toDateTimeString())->toBe(now()->addDays(15)->toDateTimeString());
    });
});

describe('tambah add-on', function () {
    it('prorata sisa masa aktif; jatuh tempo ≤ akhir masa aktif; paket tidak berubah', function () {
        paidBasic($this);

        $invoice = addonInvoice($this, $this->extraOutlet, 2);

        // 2 × 99.000 × 15 ÷ 31 = 95.806,45 → 95.806
        expect($invoice->kind)->toBe(InvoiceKind::Addon)
            ->and($invoice->amount)->toBe('95806.00')
            ->and($invoice->plan_id)->toBe($this->basic->id)
            ->and($invoice->due_at->lessThanOrEqualTo(now()->addDays(15)))->toBeTrue()
            ->and($invoice->items->sole()->quantity)->toBe(2)
            ->and($invoice->description())->toBe('Add-on: Outlet tambahan (sisa 15 hari) ×2');
    });

    it('lunas: add-on terpasang, unit kuota bertambah, lunas ulang tetap sama, paket & masa aktif tetap', function () {
        paidBasic($this);
        $ends = $this->tenant->subscription_ends_at;

        app(MarkInvoicePaid::class)->handle(addonInvoice($this, $this->extraOutlet, 2), 'manual');
        $second = addonInvoice($this, $this->extraOutlet, 1);
        app(MarkInvoicePaid::class)->handle($second, 'manual');
        app(MarkInvoicePaid::class)->handle($second, 'manual');

        $tenant = $this->tenant->fresh();
        expect(TenantAddon::forTenant($tenant->id)->sole()->quantity)->toBe(3)
            ->and($tenant->outletLimit())->toBe(4)
            ->and($tenant->plan_id)->toBe($this->basic->id)
            ->and($tenant->subscription_ends_at->equalTo($ends))->toBeTrue()
            ->and($second->refresh()->period_end->equalTo($ends))->toBeTrue();
    });

    it('menolak: trial, fitur sudah di paket, fitur sudah aktif, add-on khusus untuk owner', function (Closure $arrange, string $addon) {
        paidBasic($this);
        $arrange($this);

        expect(fn () => addonInvoice($this, $this->{$addon}))->toThrow(BusinessException::class);
        expect(SubscriptionInvoice::allTenants()->count())->toBe(0);
    })->with([
        'trial' => [fn ($t) => $t->tenant->forceFill(['status' => TenantStatus::Trial])->save(), 'extraOutlet'],
        'fitur sudah di paket' => [fn ($t) => $t->tenant->forceFill(['plan_id' => $t->bisnis->id])->save(), 'selfOrder'],
        'fitur sudah aktif' => [fn ($t) => app(SetTenantAddons::class)->handle($t->tenant, [$t->selfOrder->id => 1]), 'selfOrder'],
        'khusus' => [fn () => null, 'vip'],
    ]);

    it('admin boleh menagih add-on khusus', function () {
        paidBasic($this);

        expect(addonInvoice($this, $this->vip, 1, Admin::factory()->create())->amount)->toBe('4839.00');
    });
});

describe('berhenti', function () {
    it('berlaku sampai akhir masa aktif lalu dilepas pos:apply-plan-changes; lanjutkan membatalkan', function () {
        paidBasic($this);
        app(SetTenantAddons::class)->handle($this->tenant, [$this->selfOrder->id => 1]);
        $row = TenantAddon::forTenant($this->tenant->id)->sole();

        app(StopTenantAddon::class)->handle($row, true);
        expect($row->fresh()->status)->toBe(TenantAddon::CANCELLING);
        app(StopTenantAddon::class)->handle($row->fresh(), false);
        expect($row->fresh()->status)->toBe(TenantAddon::ACTIVE);

        app(StopTenantAddon::class)->handle($row->fresh(), true);
        app(ApplyScheduledPlanChanges::class)->handle();
        expect(TenantAddon::forTenant($this->tenant->id)->count())->toBe(1)
            ->and(Features::enabled('self_order', $this->tenant->fresh()))->toBeTrue();

        Carbon::setTestNow(now()->addDays(15)->addMinute());
        app(ApplyScheduledPlanChanges::class)->handle();
        expect(TenantAddon::forTenant($this->tenant->id)->count())->toBe(0)
            ->and(Features::enabled('self_order', $this->tenant->fresh()))->toBeFalse();
    });

    it('tanpa masa aktif berbayar berjalan: langsung dilepas', function () {
        $this->tenant->forceFill(['plan_id' => $this->basic->id, 'subscription_ends_at' => now()->subDay()])->save();
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 1]);

        expect(app(StopTenantAddon::class)->handle(TenantAddon::forTenant($this->tenant->id)->sole(), true))->toBeNull()
            ->and(TenantAddon::forTenant($this->tenant->id)->count())->toBe(0);
    });
});

describe('halaman Langganan owner', function () {
    beforeEach(function () {
        paidBasic($this);
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->tenant->id);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        CurrentOutlet::set($this->owner);
    });

    it('tambah add-on membuat tagihan prorata; daftar add-on & berhenti', function () {
        Livewire::test(SubscriptionSettings::class)
            ->assertSee('Add-on')
            ->callAction('addAddon', ['addon_id' => $this->extraOutlet->id, 'quantity' => 1])
            ->assertNotified();

        $invoice = SubscriptionInvoice::query()->sole();
        expect($invoice->kind)->toBe(InvoiceKind::Addon)->and($invoice->amount)->toBe('47903.00');

        app(SetTenantAddons::class)->handle($this->tenant, [$this->selfOrder->id => 1]);
        $row = TenantAddon::query()->sole();

        Livewire::test(SubscriptionSettings::class)
            ->assertSee('Self-order QR')
            ->callAction('stopAddon', arguments: ['id' => $row->id])
            ->assertNotified('Add-on akan berhenti di akhir masa aktif');

        expect($row->fresh()->status)->toBe(TenantAddon::CANCELLING);
    });

    it('add-on khusus & fitur yang sudah di paket tidak ditawarkan', function () {
        $offered = Livewire::test(SubscriptionSettings::class)->instance()->purchasableAddons()->pluck('code')->all();

        expect($offered)->toBe(['extra-outlet', 'self-order']);
    });

    it('tidak bisa menghentikan add-on bisnis lain (isolasi tenant)', function () {
        $other = posSetup(openShift: false);
        $otherTenant = Tenant::query()->findOrFail($other->tenantId);
        $otherTenant->forceFill(['plan_id' => $this->basic->id])->save();
        app(SetTenantAddons::class)->handle($otherTenant, [$this->extraOutlet->id => 1]);
        $foreign = TenantAddon::forTenant($otherTenant->id)->sole();
        TenantContext::set($this->tenant->id);

        // Tenant scope: baris bisnis lain tidak ditemukan → 404, tidak berubah
        Livewire::test(SubscriptionSettings::class)->callAction('stopAddon', arguments: ['id' => $foreign->id])->assertStatus(404);
        expect($foreign->fresh()->status)->toBe(TenantAddon::ACTIVE);
    });
});

it('admin: Tagih add-on + email owner berisi rincian; daftar tagihan menampilkan rincian', function () {
    Notification::fake();
    paidBasic($this);
    $owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create(['email' => 'owner@kopi.test']));
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

    Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
        ->callAction('addonInvoice', ['addon_id' => $this->vip->id, 'quantity' => 2, 'notify_owners' => true])
        ->assertHasNoActionErrors();

    $invoice = SubscriptionInvoice::allTenants()->sole();
    expect($invoice->created_by_admin_id)->not->toBeNull();
    Notification::assertSentTo($owner, InvoiceCreated::class, fn (InvoiceCreated $n): bool => str_contains($n->description, 'Karyawan VIP'));

    Livewire::test(ListSubscriptionInvoices::class)
        ->assertSee('Add-on: Karyawan VIP');
});

it('migration C2: tagihan lama mendapat satu baris paket', function () {
    $migration = require database_path('migrations/2026_11_04_100000_create_subscription_invoice_items.php');
    $migration->down();
    $id = (string) Str::uuid7();
    DB::table('subscription_invoices')->insert([
        'id' => $id, 'tenant_id' => $this->tenant->id, 'number' => 'INV-OLD-1', 'kind' => 'upgrade', 'plan_id' => $this->basic->id,
        'plan_name' => 'Basic', 'billing_cycle' => 'monthly', 'amount' => '48387.00', 'status' => InvoiceStatus::Paid->value,
        'due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    $item = SubscriptionInvoiceItem::query()->where('invoice_id', $id)->sole();
    expect($item->type)->toBe('plan')->and($item->name)->toBe('Upgrade paket Basic')->and($item->amount)->toBe('48387.00');
});
