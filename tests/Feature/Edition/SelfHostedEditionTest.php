<?php

declare(strict_types=1);

use App\Enums\TenantAccess;
use App\Enums\TenantStatus;
use App\Filament\Admin\Pages\OnboardingSettings;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Edition;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Filament\Facades\Filament;

/*
 * Edisi jual putus (ADR 0009, SPEC Q33). Env dipasang sebelum aplikasi test dibuat karena route
 * halaman depan & daftar ikut berubah.
 */
beforeAll(function () {
    putenv('POS_EDITION=self_hosted');
    $_ENV['POS_EDITION'] = $_SERVER['POS_EDITION'] = 'self_hosted';
});

afterAll(function () {
    putenv('POS_EDITION=saas');
    $_ENV['POS_EDITION'] = $_SERVER['POS_EDITION'] = 'saas';
});

it('tanpa halaman depan, daftar mandiri, dan hubungi sales', function () {
    expect(Edition::isSelfHosted())->toBeTrue()
        ->and(app(SystemSettings::class)->signupEnabled())->toBeFalse();

    $this->get('/')->assertRedirect('/dashboard/login');
    $this->get('/register')->assertNotFound();
    $this->post('/contact-sales', [])->assertNotFound();
    $this->get('/forgot-password')->assertOk();
});

it('pos:install membuat satu bisnis aktif tanpa trial, owner terverifikasi, metode bayar', function () {
    $this->artisan('pos:install', ['--business' => 'Kopi Juragan', '--owner-name' => 'Juragan', '--owner-email' => 'Owner@Juragan.test'])
        ->expectsQuestion('Kata sandi owner (min. 8 karakter, huruf & angka)', 'rahasia123')
        ->expectsQuestion('Ulangi kata sandi', 'rahasia123')
        ->assertSuccessful();

    $tenant = Tenant::query()->sole();
    $owner = User::allTenants()->where('tenant_id', $tenant->id)->sole();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->subscription_ends_at)->toBeNull()
        ->and($owner->email)->toBe('owner@juragan.test')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(TenantContext::run($tenant->id, fn () => PaymentMethod::query()->count()))->toBe(5);

    // Hanya satu bisnis
    $this->artisan('pos:install', ['--business' => 'Lain', '--owner-name' => 'X', '--owner-email' => 'x@lain.test'])->assertFailed();
    expect(Tenant::query()->count())->toBe(1);
});

it('pos:install menolak kata sandi tidak sama atau lemah', function () {
    $this->artisan('pos:install', ['--business' => 'Kopi', '--owner-name' => 'A', '--owner-email' => 'a@kopi.test'])
        ->expectsQuestion('Kata sandi owner (min. 8 karakter, huruf & angka)', 'pendek')
        ->expectsQuestion('Ulangi kata sandi', 'pendek')
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

it('tanpa trial: tanggal langganan lewat tidak membuat hanya-baca; tangguhkan tetap memblokir', function () {
    $tenant = Tenant::factory()->expired()->make();
    $suspended = Tenant::factory()->suspended()->make();

    expect($tenant->access())->toBe(TenantAccess::Full)
        ->and($tenant->trialDaysLeft())->toBeNull()
        ->and($suspended->access())->toBe(TenantAccess::Blocked);
});

it('panel admin: tanpa calon pelanggan & pendaftaran; tambah tenant hanya bila belum ada', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

    expect(SalesLeadResource::canAccess())->toBeFalse()
        ->and(OnboardingSettings::canAccess())->toBeFalse()
        ->and(TenantResource::canCreate())->toBeTrue();

    Tenant::factory()->create();
    expect(TenantResource::canCreate())->toBeFalse();
});
