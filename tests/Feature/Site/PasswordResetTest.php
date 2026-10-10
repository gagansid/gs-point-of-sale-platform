<?php

declare(strict_types=1);

use App\Actions\Auth\SendOwnerInvitation;
use App\Models\User;
use App\Notifications\InviteOwner;
use App\Notifications\ResetUserPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Lupa kata sandi & undangan owner (SPEC Q34). Mode satu domain.
 */
beforeEach(function () {
    Notification::fake();
    RateLimiter::clear(sha1('127.0.0.1'));
    $this->owner = User::factory()->owner()->create(['email' => 'owner@kopi.test', 'password' => 'lama12345']);
});

function resetBody(string $token, array $override = []): array
{
    return ['token' => $token, 'email' => 'owner@kopi.test', 'password' => 'baru12345', 'password_confirmation' => 'baru12345', ...$override];
}

it('form lupa kata sandi & pesan sama untuk email terdaftar maupun tidak', function () {
    $this->get('/forgot-password')->assertOk()->assertSee('Lupa kata sandi');

    $this->post('/forgot-password', ['email' => 'tidak@ada.test'])->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post('/forgot-password', ['email' => 'OWNER@kopi.test'])->assertSessionHas('status');
    Notification::assertSentTo($this->owner, ResetUserPassword::class);
});

it('reset kata sandi: berganti, email terverifikasi, semua token lama diputus', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    $this->owner->createToken('app');
    $token = Password::broker('users')->createToken($this->owner);

    $this->get("/reset-password/{$token}?email=owner@kopi.test")->assertOk()->assertSee('Buat kata sandi baru');
    $this->post('/reset-password', resetBody($token))->assertRedirect()->assertSessionHas('status');

    $owner = $this->owner->refresh();
    expect(Hash::check('baru12345', (string) $owner->password))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->tokens()->count())->toBe(0);
});

it('token salah, kedaluwarsa 60 menit, atau dipakai di jalur undangan ditolak', function () {
    $token = Password::broker('users')->createToken($this->owner);

    $this->post('/reset-password', resetBody('salah'))->assertSessionHasErrors('email');
    $this->post('/reset-password', resetBody($token, ['invitation' => 1]))->assertSessionHasErrors('email');

    $this->travel(61)->minutes();
    $this->post('/reset-password', resetBody($token))->assertSessionHasErrors('email');

    expect(Hash::check('lama12345', (string) $this->owner->refresh()->password))->toBeTrue();
});

it('undangan owner berlaku 3 hari dan memakai tabel token sendiri', function () {
    app(SendOwnerInvitation::class)->handle($this->owner, 'Kopi Senja');

    $token = null;
    Notification::assertSentTo($this->owner, InviteOwner::class, function (InviteOwner $notification) use (&$token): bool {
        parse_str((string) parse_url($notification->toMail($this->owner)->actionUrl, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($notification->toMail($this->owner)->actionUrl, PHP_URL_PATH));

        return ($query['invite'] ?? null) === '1';
    });

    // Token undangan tidak berlaku di jalur lupa kata sandi
    $this->post('/reset-password', resetBody((string) $token))->assertSessionHasErrors('email');

    $this->travel(2)->days();
    $this->post('/reset-password', resetBody((string) $token, ['invitation' => 1]))->assertSessionHas('status');
    expect(Hash::check('baru12345', (string) $this->owner->refresh()->password))->toBeTrue();
});

it('validasi kata sandi baru & akun nonaktif tidak bisa reset', function () {
    $token = Password::broker('users')->createToken($this->owner);
    $this->post('/reset-password', resetBody($token, ['password' => 'pendek', 'password_confirmation' => 'pendek']))->assertSessionHasErrors('password');

    $this->owner->forceFill(['is_active' => false])->save();
    $this->post('/forgot-password', ['email' => 'owner@kopi.test']);
    Notification::assertNotSentTo($this->owner, ResetUserPassword::class);
});
