<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

it('membuat super admin dengan kata sandi kuat', function () {
    $this->artisan('pos:create-admin', ['--name' => 'Gagan', '--email' => 'Admin@Contoh.test'])
        ->expectsQuestion('Kata sandi (min. 12 karakter, huruf besar & kecil, angka, simbol)', 'Rahasia-Kuat-2026!')
        ->expectsQuestion('Ulangi kata sandi', 'Rahasia-Kuat-2026!')
        ->assertSuccessful();

    $admin = Admin::query()->sole();
    expect($admin->email)->toBe('admin@contoh.test')
        ->and(Hash::check('Rahasia-Kuat-2026!', $admin->password))->toBeTrue();
});

it('menolak kata sandi lemah', function () {
    $this->artisan('pos:create-admin', ['--name' => 'Gagan', '--email' => 'admin@contoh.test'])
        ->expectsQuestion('Kata sandi (min. 12 karakter, huruf besar & kecil, angka, simbol)', 'password123')
        ->expectsQuestion('Ulangi kata sandi', 'password123')
        ->assertFailed();

    expect(Admin::query()->count())->toBe(0);
});

it('menolak email yang sudah terdaftar dan konfirmasi yang berbeda', function () {
    Admin::factory()->create(['email' => 'admin@contoh.test']);

    $this->artisan('pos:create-admin', ['--name' => 'Lain', '--email' => 'admin@contoh.test'])
        ->expectsQuestion('Kata sandi (min. 12 karakter, huruf besar & kecil, angka, simbol)', 'Rahasia-Kuat-2026!')
        ->expectsQuestion('Ulangi kata sandi', 'Rahasia-Kuat-2026!')
        ->assertFailed();

    $this->artisan('pos:create-admin', ['--name' => 'Lain', '--email' => 'baru@contoh.test'])
        ->expectsQuestion('Kata sandi (min. 12 karakter, huruf besar & kecil, angka, simbol)', 'Rahasia-Kuat-2026!')
        ->expectsQuestion('Ulangi kata sandi', 'Beda-Kuat-2026!')
        ->assertFailed();

    expect(Admin::query()->count())->toBe(1);
});
