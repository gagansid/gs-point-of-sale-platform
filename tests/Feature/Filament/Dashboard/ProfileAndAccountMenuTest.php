<?php

declare(strict_types=1);

use App\Actions\SelfOrder\RegenerateTableQr;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\User\LogoutOtherSessions;
use App\Actions\User\UpdateOwnAvatar;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Profil (foto, HP, info akun, perangkat & sesi), menu akun, dan kartu/URL pendek QR meja. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['name' => 'Kopi Senja Kemang Raya Selatan Jakarta']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create(['name' => 'Rina Owner', 'password' => 'rahasia-lama-123', 'email_verified_at' => now()]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

it('profil: bagian lengkap, info akun, simpan nomor HP (dinormalisasi) tanpa kata sandi saat ini', function () {
    $this->get(EditProfile::getUrl())->assertOk()
        ->assertSee('Data diri')->assertSee('Keamanan')->assertSee('Info akun')->assertSee('Perangkat &amp; sesi', false)
        ->assertSee('id="keamanan"', false)->assertSee('id="perangkat"', false)->assertSee('Owner');

    Livewire::test(EditProfile::class)->fillForm(['phone' => '0812-3456-7890'])->call('save')->assertHasNoFormErrors();
    expect($this->owner->refresh()->phone)->toBe('+6281234567890');

    Livewire::test(EditProfile::class)->fillForm(['phone' => '123'])->call('save')->assertHasErrors(['data.phone']);
    expect($this->owner->refresh()->phone)->toBe('+6281234567890');
});

it('foto profil: unggah ke folder tenant, ganti menghapus foto lama, tampil sebagai avatar; path asing ditolak', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['avatar_path' => UploadedFile::fake()->image('saya.jpg', 300, 300)])
        ->call('save')->assertHasNoFormErrors();

    $first = $this->owner->refresh()->avatar_path;
    expect($first)->toStartWith('avatars/'.$this->owner->tenant_id.'/')
        ->and(Storage::disk('public')->exists($first))->toBeTrue()
        ->and($this->owner->getFilamentAvatarUrl())->toContain($first);

    Storage::disk('public')->put('avatars/'.$this->owner->tenant_id.'/baru.jpg', 'x');
    app(UpdateOwnAvatar::class)->handle($this->owner, 'avatars/'.$this->owner->tenant_id.'/baru.jpg');
    expect(Storage::disk('public')->exists($first))->toBeFalse();

    expect(fn () => app(UpdateOwnAvatar::class)->handle($this->owner, 'avatars/tenant-lain/x.jpg'))->toThrow(BusinessException::class);

    app(UpdateOwnAvatar::class)->handle($this->owner, null);
    expect($this->owner->refresh()->avatar_path)->toBeNull()->and($this->owner->getFilamentAvatarUrl())->toBeNull();
});

it('keluar dari semua perangkat lain: sesi lain & token API dihapus, sesi ini tetap', function () {
    DB::table('sessions')->insert([
        ['id' => 'sesi-ini', 'user_id' => $this->owner->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'Chrome/1 Mac OS', 'payload' => 'x', 'last_activity' => time()],
        ['id' => 'sesi-lain', 'user_id' => $this->owner->id, 'ip_address' => '2.2.2.2', 'user_agent' => 'Firefox/1 Windows', 'payload' => 'x', 'last_activity' => time()],
    ]);
    $this->owner->createToken('Tablet kasir');

    expect(app(LogoutOtherSessions::class)->handle($this->owner, 'sesi-ini'))->toBe(['sessions' => 1, 'tokens' => 1])
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['sesi-ini']);
});

it('menu akun: hanya Profil, Bantuan, Keluar; tombol tema di kiri nama (di luar menu)', function () {
    $html = $this->get(EditProfile::getUrl())->assertOk()->getContent();
    $menu = substr($html, (int) strpos($html, 'fi-user-menu'));

    expect($menu)->toContain('Profil')->toContain('Bantuan')->toContain('Keluar')
        ->not->toContain('Kata sandi &amp; PIN')->not->toContain('gs-account-card')->not->toContain('fi-theme-switcher')
        ->and(strpos($html, 'gs-theme-toggle'))->toBeLessThan(strpos($html, 'fi-user-menu'));
});

it('QR meja: URL pendek dialihkan ke URL asli, ganti QR mengganti kode; kartu ringkas dengan nama panjang dipotong', function () {
    $table = app(SaveDiningTable::class)->handle(null, $this->outlet, ['name' => 'Meja 5', 'area' => 'Teras belakang dekat taman']);
    expect($table->short_code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/')->and($table->shortUrl())->toEndWith('/t/'.$table->short_code);

    $this->get('/t/'.strtolower($table->short_code))->assertRedirect($table->orderUrl());

    $old = $table->short_code;
    app(RegenerateTableQr::class)->handle($table);
    $this->get('/t/'.$old)->assertNotFound();
    $this->get('/t/'.$table->refresh()->short_code)->assertRedirect($table->orderUrl());

    $this->get(route('filament.dashboard.tables.print'))->assertOk()
        ->assertSee('Meja 5')->assertSee(preg_replace('#^https?://#', '', $table->shortUrl()))
        ->assertSee('Kopi Senja Kemang Raya Selatan Jakarta')->assertSee('text-overflow: ellipsis', false)
        ->assertDontSee($table->qr_token);
});
