<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Quotas;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Pemakaian kuota (ADR 0025 B2): halaman Langganan owner, detail bisnis admin, banner lewat batas, auth/me
 * tenant.limits. posSetup(): 1 outlet, 1 perangkat, 2 karyawan + owner = 3 karyawan aktif, 2 produk.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->forOutlet($this->pos->outlet)->create(['email_verified_at' => now()]));
    TenantContext::forget();
    $this->plan = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['users' => 2, 'products' => 5]]);
    $this->tenant->forceFill(['plan_id' => $this->plan->id])->save();
});

afterEach(fn () => config(['pos.edition' => 'saas']));

function ownerDashboard(object $test): void
{
    Filament::setCurrentPanel('dashboard');
    TenantContext::set($test->pos->tenantId);
    $test->actingAs($test->owner);
    CurrentOutlet::set($test->owner);
}

it('usage & exceeded: semua kuota master data; exceeded hanya yang punya batas & melebihi', function () {
    $usage = collect(Quotas::usage($this->tenant))->keyBy('key');

    expect($usage->keys()->all())->toBe(['outlets', 'users', 'devices', 'products', 'members'])
        ->and($usage['users'])->toBe(['key' => 'users', 'name' => 'Karyawan aktif', 'limit' => 2, 'used' => 3])
        ->and($usage['devices']['limit'])->toBeNull()
        ->and(Quotas::exceeded($this->tenant))->toBe([['key' => 'users', 'name' => 'Karyawan aktif', 'limit' => 2, 'used' => 3]]);
});

it('auth/me mengirim tenant.limits {limit, used}', function () {
    $this->withToken(userToken($this->owner, $this->pos->device))->getJson('/api/v1/auth/me', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.tenant.limits.users', ['limit' => 2, 'used' => 3])
        ->assertJsonPath('data.tenant.limits.products', ['limit' => 5, 'used' => 2])
        ->assertJsonPath('data.tenant.limits.devices', ['limit' => null, 'used' => 1]);
});

it('halaman Langganan owner menampilkan pemakaian vs batas & tanda lewat batas', function () {
    ownerDashboard($this);

    Livewire::test(SubscriptionSettings::class)
        ->assertSee('Pemakaian kuota')
        ->assertSee('3 / 2')
        ->assertSee('2 / 5')
        ->assertSee('Melebihi paket: tidak bisa menambah sampai di bawah batas')
        ->assertSee('Tanpa batas');
});

it('banner lewat batas tampil untuk owner, tidak untuk karyawan tanpa akses langganan, hilang bila di bawah batas', function () {
    ownerDashboard($this);
    $url = SubscriptionSettings::getUrl();

    $this->get($url)->assertOk()->assertSee('Pemakaian melebihi batas paket Basic')->assertSee('Karyawan aktif 3 dari 2');

    $manager = TenantContext::run($this->pos->tenantId, fn () => User::factory()->manager()->forOutlet($this->pos->outlet)->create(['email_verified_at' => now()]));
    $this->actingAs($manager);
    $this->get(Filament::getPanel('dashboard')->getUrl())->assertDontSee('Pemakaian melebihi batas');

    $this->plan->forceFill(['limits' => ['users' => 10]])->save();
    // Request nyata memuat ulang user & paket; di test objek guard sama, jadi pakai user segar
    $this->actingAs($this->owner->fresh());
    $this->get($url)->assertOk()->assertDontSee('Pemakaian melebihi batas');
});

it('detail bisnis admin menampilkan pemakaian kuota', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

    Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
        ->assertOk()
        ->assertSee('Karyawan aktif')
        ->assertSee('3 / 2')
        ->assertSee('Melebihi paket');
});
