<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\SecuritySettings;
use App\Models\Admin;
use App\Models\SystemSetting;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = Admin::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin, 'admin');
});

it('menampilkan status kebijakan dan status 2FA akun', function () {
    $this->get('/admin/security')
        ->assertOk()
        ->assertSee('Verifikasi dua langkah (2FA)')
        ->assertSee('Wajib')
        ->assertSee('2FA aktif');
});

it('menolak mengubah kebijakan tanpa kata sandi yang benar', function () {
    Livewire::test(SecuritySettings::class)
        ->callAction('toggleTwoFactor', data: ['current_password' => 'salah'])
        ->assertHasActionErrors(['current_password']);

    expect(app(SystemSettings::class)->adminTwoFactorRequired())->toBeTrue();
});

it('menonaktifkan lalu mengaktifkan kembali wajib 2FA dengan kata sandi', function () {
    Livewire::test(SecuritySettings::class)
        ->callAction('toggleTwoFactor', data: ['current_password' => 'password'])
        ->assertHasNoActionErrors();

    expect(app(SystemSettings::class)->adminTwoFactorRequired())->toBeFalse()
        ->and(SystemSetting::query()->find(SystemSettings::ADMIN_TWO_FACTOR_REQUIRED)?->updated_by)->toBe($this->admin->id);

    Livewire::test(SecuritySettings::class)
        ->callAction('toggleTwoFactor', data: ['current_password' => 'password'])
        ->assertHasNoActionErrors();

    expect(app(SystemSettings::class)->adminTwoFactorRequired())->toBeTrue();
});
