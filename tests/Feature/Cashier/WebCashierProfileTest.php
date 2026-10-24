<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** Kasir web → Profil: data diri (foto, nama, No. HP) & keamanan (kata sandi, PIN) milik pengguna sendiri. */
beforeEach(function () {
    Storage::fake('public');
    $this->pos = posSetup(openShift: false);
    $this->pos->cashier->forceFill(['email' => 'budi@kopi.test', 'username' => 'budi'])->save();
    webCashierLogin($this, $this->pos, $this->pos->cashier);
});

function cashierFresh(object $test): User
{
    return TenantContext::run($test->pos->tenantId, fn (): User => User::query()->findOrFail($test->pos->cashier->id));
}

it('halaman profil: info akun, tautan dari header; email & username hanya tampil', function () {
    $this->get(route('cashier.shift'))->assertOk()->assertSee('href="'.route('cashier.profile').'"', false);

    $this->get(route('cashier.profile'))->assertOk()
        ->assertSee('Data diri')->assertSee('Keamanan')
        ->assertSee('Budi')->assertSee('Kasir')->assertSee('budi@kopi.test')->assertSee('budi')
        ->assertDontSee('name="email"', false)->assertDontSee('name="username"', false);
});

it('ubah nama & No. HP; HP tidak valid ditolak; email tidak berubah', function () {
    $this->post(route('cashier.profile.update'), ['name' => 'Budi Santoso', 'phone' => '0812-3456-7890'])
        ->assertRedirect(route('cashier.profile'))->assertSessionHas('status', 'Profil disimpan');

    $user = cashierFresh($this);
    expect($user->name)->toBe('Budi Santoso')->and($user->phone)->toBe('+6281234567890')->and($user->email)->toBe('budi@kopi.test');

    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'phone' => '123'])->assertSessionHasErrors('phone');
    $this->post(route('cashier.profile.update'), ['name' => '=cmd'])->assertSessionHasErrors('name');
});

it('foto profil: unggah ke folder tenant, ganti menghapus yang lama, bisa dihapus; bukan gambar ditolak', function () {
    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'avatar' => UploadedFile::fake()->image('a.png', 200, 200)])->assertRedirect();
    $first = cashierFresh($this)->avatar_path;
    expect($first)->toStartWith('avatars/'.$this->pos->tenantId.'/');
    Storage::disk('public')->assertExists($first);

    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
    Storage::disk('public')->assertMissing($first);

    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'remove_avatar' => '1']);
    expect(cashierFresh($this)->avatar_path)->toBeNull();

    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'avatar' => UploadedFile::fake()->create('x.php', 10, 'text/x-php')])
        ->assertSessionHasErrors('avatar');

    // Foto kamera 12 MP (4032×3024) diterima bila browser tidak sempat memperkecil; > 2 MB tetap ditolak
    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'avatar' => UploadedFile::fake()->image('IMG_0001.jpg', 4032, 3024)])
        ->assertSessionHasNoErrors();
    $this->post(route('cashier.profile.update'), ['name' => 'Budi', 'avatar' => UploadedFile::fake()->image('besar.jpg', 800, 800)->size(3000)])
        ->assertSessionHasErrors('avatar');
});

it('ganti kata sandi: wajib kata sandi saat ini; browser ini tetap masuk, token aplikasi dicabut', function () {
    $this->post(route('cashier.profile.security'), ['current_password' => 'salah', 'password' => 'baru-rahasia1', 'password_confirmation' => 'baru-rahasia1'])
        ->assertSessionHasErrors('current_password');
    $this->post(route('cashier.profile.security'), ['current_password' => 'password'])->assertSessionHasErrors('password');
    $this->post(route('cashier.profile.security'), ['current_password' => 'password', 'password' => 'lemah', 'password_confirmation' => 'lemah'])
        ->assertSessionHasErrors('password');

    $this->post(route('cashier.profile.security'), ['current_password' => 'password', 'password' => 'baru-rahasia1', 'password_confirmation' => 'baru-rahasia1'])
        ->assertRedirect(route('cashier.profile'))->assertSessionHas('status', 'Kata sandi diganti');

    $user = cashierFresh($this);
    expect(Hash::check('baru-rahasia1', (string) $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(session(WebCashier::SESSION_PASSWORD))->toBe(WebCashier::passwordFingerprint($user));

    // Browser ini tetap masuk: sidik di sesi cocok dengan kata sandi baru (guard test diisi ulang dengan user terbaru)
    $this->actingAs($user, WebCashier::GUARD)->get(route('cashier.profile'))->assertOk();
});

it('ganti PIN: PIN lemah ditolak, PIN baru berlaku', function () {
    $this->post(route('cashier.profile.security'), ['current_password' => 'password', 'pin' => '111111'])->assertSessionHasErrors('pin');
    $this->post(route('cashier.profile.security'), ['current_password' => 'password', 'pin' => '12'])->assertSessionHasErrors('pin');

    $this->post(route('cashier.profile.security'), ['current_password' => 'password', 'pin' => '483920'])
        ->assertSessionHas('status', 'PIN diganti');
    expect(Hash::check('483920', (string) cashierFresh($this)->pin))->toBeTrue();
});

it('tanpa masuk kasir web diarahkan ke halaman masuk', function () {
    auth(WebCashier::GUARD)->logout();
    $this->flushSession();

    $this->get(route('cashier.profile'))->assertRedirect(route('cashier.login'));
    $this->post(route('cashier.profile.update'), ['name' => 'X'])->assertRedirect(route('cashier.login'));
});
