<?php

declare(strict_types=1);

use App\Models\Admin;

it('menghapus 2FA admin yang kehilangan authenticator', function () {
    $admin = Admin::factory()->withTwoFactor()->create(['email' => 'admin@contoh.test']);

    $this->artisan('pos:admin-reset-2fa', ['email' => 'Admin@Contoh.test'])
        ->expectsConfirmation('Hapus 2FA milik admin@contoh.test?', 'yes')
        ->assertSuccessful();

    expect($admin->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('gagal untuk email yang tidak terdaftar', function () {
    $this->artisan('pos:admin-reset-2fa', ['email' => 'tidak@ada.test'])->assertFailed();
});
