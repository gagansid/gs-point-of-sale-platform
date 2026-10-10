<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\OnboardingSettings;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Models\Admin;
use App\Models\User;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

it('default trial 14 hari & pendaftaran terbuka', function () {
    $settings = app(SystemSettings::class);

    expect($settings->trialDays())->toBe(14)
        ->and($settings->signupEnabled())->toBeTrue();
});

it('super admin mengubah lama trial & menutup pendaftaran', function () {
    Livewire::test(OnboardingSettings::class)
        ->assertSet('data.trial_days', 14)
        ->set('data.trial_days', 30)
        ->set('data.signup_enabled', false)
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SystemSettings::class);
    expect($settings->trialDays())->toBe(30)
        ->and($settings->signupEnabled())->toBeFalse();
});

it('validasi lama trial 1–90 hari', function (mixed $days) {
    Livewire::test(OnboardingSettings::class)
        ->set('data.trial_days', $days)
        ->call('save')
        ->assertHasFormErrors(['trial_days']);

    expect(app(SystemSettings::class)->trialDays())->toBe(14);
})->with([0, 91, 'abc']);

it('tenant baru di admin memakai lama trial dari setelan', function () {
    app(SystemSettings::class)->setOnboarding(21, true);
    $this->freezeTime();

    $state = Livewire::test(CreateTenant::class)->get('data.subscription_ends_at');

    expect(substr((string) $state, 0, 10))->toBe(now()->addDays(21)->toDateString());
});

it('akun tenant tidak bisa membuka halaman setelan admin', function () {
    auth('admin')->logout();
    $this->actingAs(User::factory()->owner()->create());

    expect($this->get('/admin/onboarding')->status())->toBeIn([302, 403]);
});
