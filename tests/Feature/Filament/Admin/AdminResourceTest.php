<?php

declare(strict_types=1);

use App\Actions\Admin\ManageAdmin;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\Admins\Pages\ManageAdmins;
use App\Models\Admin;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/** Sistem → Admin: kelola akun super admin. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->me = Admin::factory()->withTwoFactor()->create(['password' => 'Rahasia-Admin-1!']);
    $this->actingAs($this->me, 'admin');
});

it('tambah admin: kata sandi kuat + konfirmasi kata sandi sendiri', function () {
    Livewire::test(ManageAdmins::class)
        ->callAction('create', ['name' => 'Rina', 'email' => 'RINA@gspos.test', 'password' => 'lemah123', 'current_password' => 'Rahasia-Admin-1!'])
        ->assertHasFormErrors(['password']);

    Livewire::test(ManageAdmins::class)
        ->callAction('create', ['name' => 'Rina', 'email' => 'RINA@gspos.test', 'password' => 'Kuat-Sekali-123!', 'current_password' => 'salah'])
        ->assertHasFormErrors(['current_password']);

    Livewire::test(ManageAdmins::class)
        ->callAction('create', ['name' => 'Rina', 'email' => 'RINA@gspos.test', 'password' => 'Kuat-Sekali-123!', 'current_password' => 'Rahasia-Admin-1!'])
        ->assertHasNoFormErrors();

    $rina = Admin::query()->where('email', 'rina@gspos.test')->sole();
    expect(Hash::check('Kuat-Sekali-123!', $rina->password))->toBeTrue()
        ->and($rina->hasTwoFactorEnabled())->toBeFalse();
});

it('ubah tanpa mengganti kata sandi', function () {
    $other = Admin::factory()->create(['password' => 'Lama-Sekali-123!']);

    Livewire::test(ManageAdmins::class)
        ->callAction(TestAction::make('edit')->table($other), ['name' => 'Baru', 'email' => $other->email, 'password' => '', 'current_password' => 'Rahasia-Admin-1!'])
        ->assertHasNoFormErrors();

    expect($other->fresh()?->name)->toBe('Baru')->and(Hash::check('Lama-Sekali-123!', (string) $other->fresh()?->password))->toBeTrue();
});

it('reset 2FA admin lain; tidak untuk diri sendiri', function () {
    $other = Admin::factory()->withTwoFactor()->create();

    Livewire::test(ManageAdmins::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($this->me))
        ->callAction(TestAction::make('resetTwoFactor')->table($other), ['current_password' => 'Rahasia-Admin-1!']);

    expect($other->fresh()?->hasTwoFactorEnabled())->toBeFalse()
        ->and($this->me->fresh()?->hasTwoFactorEnabled())->toBeTrue();
});

it('hapus admin lain; diri sendiri & admin terakhir dilindungi', function () {
    $other = Admin::factory()->create();

    Livewire::test(ManageAdmins::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->me))
        ->callAction(TestAction::make('delete')->table($other), ['current_password' => 'Rahasia-Admin-1!']);
    expect(Admin::query()->count())->toBe(1);

    expect(fn () => app(ManageAdmin::class)->delete($this->me, $this->me))
        ->toThrow(BusinessException::class);
});
