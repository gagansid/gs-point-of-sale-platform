<?php

declare(strict_types=1);

use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\Outlet;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * GSPOS-SEC-012 & GSPOS-SEC-013 (docs/security/reports/2026-10-10-audit.md): halaman Profil
 * dashboard menyimpan lewat Action — email baru wajib diverifikasi ulang, ganti kata sandi
 * mencabut token API.
 */
beforeEach(function () {
    Notification::fake();
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::factory()->create();
    TenantContext::set($outlet->tenant_id);
    $this->owner = User::factory()->owner()->create([
        'email' => 'owner@kopi.test',
        'password' => 'rahasia-lama-123',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

it('ganti email: disimpan huruf kecil, verifikasi direset, link dikirim ke alamat baru', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['email' => 'Baru@Kopi.Test', 'currentPassword' => 'rahasia-lama-123'])
        ->call('save')
        ->assertHasNoFormErrors();

    $owner = $this->owner->refresh();
    expect($owner->email)->toBe('baru@kopi.test')
        ->and($owner->email_verified_at)->toBeNull();
    Notification::assertSentTo($owner, VerifyOwnerEmail::class);
});

it('ganti kata sandi: semua token API dicabut', function () {
    userToken($this->owner);
    expect($this->owner->tokens()->count())->toBe(1);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'password' => 'rahasia-baru-456',
            'passwordConfirmation' => 'rahasia-baru-456',
            'currentPassword' => 'rahasia-lama-123',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $owner = $this->owner->refresh();
    expect($owner->tokens()->count())->toBe(0)
        ->and(Hash::check('rahasia-baru-456', $owner->password))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull();
});

it('ganti nama saja: email tetap terverifikasi, token tetap', function () {
    userToken($this->owner);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Nama Baru'])
        ->call('save')
        ->assertHasNoFormErrors();

    $owner = $this->owner->refresh();
    expect($owner->name)->toBe('Nama Baru')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->tokens()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('ganti email tanpa kata sandi saat ini ditolak', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['email' => 'baru@kopi.test'])
        ->call('save')
        ->assertHasFormErrors(['currentPassword' => 'required']);

    expect($this->owner->refresh()->email)->toBe('owner@kopi.test');
});
