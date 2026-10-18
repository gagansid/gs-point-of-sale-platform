<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Actions\Payment\StartOnlinePayment;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Enums\ErrorCode;
use App\Enums\ReportTab;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\PaymentGatewaySettings;
use App\Filament\Dashboard\Pages\Reports;
use App\Models\Feature;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportExporter;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Fitur berbayar ADR 0025 A2: advanced_reports, report_export, web_cashier, online_payment — master data, isi awal
 * paket lama, dan pengecekan di dashboard, API, kasir web, serta pembayaran online pelanggan.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->forOutlet($this->pos->outlet)->create());
    TenantContext::forget();
    $all = array_keys(Feature::options());
    $this->full = app(SavePlan::class)->handle(null, ['code' => 'full', 'name' => 'Full', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'features' => $all]);
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => ['member', 'self_order']]);
});

function paidPlan(Tenant $tenant, Plan $plan): void
{
    $tenant->forceFill(['plan_id' => $plan->id])->save();
    freshAuth();
}

it('migration A2: paket lama tetap punya fitur yang dulu gratis; online_payment hanya bila ada self_order', function () {
    $migration = require database_path('migrations/2026_11_01_200000_add_paid_features.php');
    $migration->down();
    $selfOrder = app(SavePlan::class)->handle(null, ['code' => 'so', 'name' => 'SO', 'price_monthly' => '1', 'price_yearly' => '1', 'features' => ['self_order']]);
    $plain = app(SavePlan::class)->handle(null, ['code' => 'plain', 'name' => 'Plain', 'price_monthly' => '1', 'price_yearly' => '1', 'features' => []]);

    $migration->up();

    expect(DB::table('features')->whereIn('key', ['advanced_reports', 'report_export', 'web_cashier', 'online_payment'])->count())->toBe(4)
        ->and($selfOrder->refresh()->features)->toBe(['self_order', 'advanced_reports', 'report_export', 'web_cashier', 'online_payment'])
        ->and($plain->refresh()->features)->toBe(['advanced_reports', 'report_export', 'web_cashier']);
});

describe('advanced_reports', function () {
    it('tab laporan lanjutan tersembunyi; Diskon & void tetap ada', function () {
        TenantContext::set($this->pos->tenantId);
        paidPlan($this->tenant, $this->basic);
        TenantContext::set($this->pos->tenantId);

        $tabs = ReportTab::visibleTabs($this->owner);
        expect($tabs)->not->toContain(ReportTab::Profit, ReportTab::Options, ReportTab::Employees, ReportTab::Time)
            ->and($tabs)->toContain(ReportTab::Summary, ReportTab::Products, ReportTab::Discounts, ReportTab::Shifts);

        paidPlan($this->tenant, $this->full);
        TenantContext::set($this->pos->tenantId);
        expect(ReportTab::visibleTabs($this->owner))->toContain(ReportTab::Profit, ReportTab::Options, ReportTab::Employees, ReportTab::Time);
    });

    it('API laporan lanjutan & pembelian ditolak; laporan dasar tetap jalan', function () {
        paidPlan($this->tenant, $this->basic);
        $token = userToken($this->owner, $this->pos->device);
        $query = '?from=2026-10-01&to=2026-10-10';

        foreach (['profit', 'options', 'employees', 'heatmap'] as $endpoint) {
            freshAuth();
            assertApiError($this->withToken($token)->getJson("/api/v1/reports/{$endpoint}{$query}", apiHeaders()), 'FEATURE_NOT_AVAILABLE', 403);
        }

        freshAuth();
        $this->withToken($token)->getJson("/api/v1/reports/purchases{$query}", apiHeaders())
            ->assertForbidden()->assertJsonPath('error.details.feature', 'stock');

        foreach (['summary', 'hourly', 'audit', 'products'] as $endpoint) {
            freshAuth();
            $this->withToken($token)->getJson("/api/v1/reports/{$endpoint}{$query}", apiHeaders())->assertOk();
        }

        paidPlan($this->tenant, $this->full);
        $this->withToken($token)->getJson("/api/v1/reports/profit{$query}", apiHeaders())->assertOk();
    });
});

describe('report_export', function () {
    it('tombol export laporan tersembunyi dan exporter menolak', function () {
        paidPlan($this->tenant, $this->basic);
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->pos->tenantId);
        $this->actingAs($this->owner);
        CurrentOutlet::set($this->owner);

        Livewire::test(Reports::class)->assertActionHidden('excel_full');

        expect(fn () => app(ReportExporter::class)->excel($this->owner, ReportContext::fromInput([], $this->owner), null))
            ->toThrow(BusinessException::class, 'Fitur ini tidak termasuk paket langganan bisnis Anda');
    });

    it('paket dengan export: tombol tampil', function () {
        paidPlan($this->tenant, $this->full);
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->pos->tenantId);
        $this->actingAs($this->owner);
        CurrentOutlet::set($this->owner);

        Livewire::test(Reports::class)->assertActionVisible('excel_full');
    });
});

describe('web_cashier', function () {
    it('login kasir web ditolak dengan pesan paket', function () {
        $this->tenant->forceFill(['slug' => 'kopi-paket'])->save();
        $this->pos->cashier->forceFill(['username' => 'budi', 'password' => 'rahasia-123', 'web_cashier_access' => true])->save();
        paidPlan($this->tenant, $this->basic);

        $this->post(route('cashier.login.store'), ['mode' => 'username', 'business' => 'kopi-paket', 'login' => 'budi', 'password' => 'rahasia-123'])
            ->assertSessionHasErrors(['login' => 'Kasir web tidak termasuk paket langganan bisnis Anda. Gunakan aplikasi kasir.']);
        $this->assertGuest('cashier');
    });

    it('sesi kasir web yang terbuka dikeluarkan saat paket tidak lagi memuat kasir web', function () {
        paidPlan($this->tenant, $this->full);
        webCashierLogin($this, $this->pos, $this->pos->cashier);
        TenantContext::forget();
        // Tanpa shift terbuka kasir diarahkan ke halaman shift, bukan dikeluarkan
        expect($this->get(route('cashier.home'))->headers->get('Location'))->not->toBe(route('cashier.login'));

        // Paket turun di tengah sesi: cukup lupakan cache fitur (sesi login tetap)
        $this->tenant->forceFill(['plan_id' => $this->basic->id])->save();
        Features::flush();
        TenantContext::forget();
        // Guard test memakai objek user yang sama: buang relasi tenant lama (request nyata memuat ulang user)
        $this->pos->cashier->unsetRelation('tenant');
        $this->get(route('cashier.home'))
            ->assertRedirect(route('cashier.login'))
            ->assertSessionHas('status', 'Kasir web tidak termasuk paket langganan bisnis Anda. Gunakan aplikasi kasir.');
    });
});

describe('online_payment', function () {
    beforeEach(function () {
        TenantContext::set($this->pos->tenantId);
        app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz']);
        $this->actingAs($this->owner);
    });

    it('tanpa fitur: pembayaran online ditolak sebagai belum diaktifkan & pengaturan Midtrans tersembunyi', function () {
        paidPlan($this->tenant, $this->basic);
        TenantContext::set($this->pos->tenantId);
        $this->actingAs($this->owner);

        expect(fn () => app(StartOnlinePayment::class)->handle(new Order, 'Andi', 'https://example.test'))
            ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::PaymentGatewayNotConfigured->value))
            ->and(PaymentGatewaySettings::canAccess())->toBeFalse();
    });

    it('dengan fitur: lolos gerbang paket', function () {
        paidPlan($this->tenant, $this->full);
        TenantContext::set($this->pos->tenantId);
        $this->actingAs($this->owner);

        // Order bukan self-order → NOT_FOUND: artinya sudah melewati cek fitur & kesiapan gateway
        expect(fn () => app(StartOnlinePayment::class)->handle(new Order, 'Andi', 'https://example.test'))
            ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::NotFound->value))
            ->and(PaymentGatewaySettings::canAccess())->toBeTrue()
            ->and(Features::enabled('online_payment'))->toBeTrue();
    });
});
