<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Daftar mandiri gspos.id/daftar + verifikasi email (ADR 0009, SPEC Q30–Q32). Mode satu domain.
 */
beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('signup-h:127.0.0.1');
    RateLimiter::clear('signup-d:127.0.0.1');
});

function signupBody(array $override = []): array
{
    return [
        'business_name' => 'Kopi Senja', 'business_type' => 'cafe', 'name' => 'Budi',
        'email' => 'Budi@KopiSenja.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ...$override,
    ];
}

it('menampilkan form daftar dengan lama trial dari setelan', function () {
    app(SystemSettings::class)->setOnboarding(30, true);

    $this->get('/daftar')->assertOk()->assertSee('Coba gratis 30 hari');
    $this->get('/')->assertSee('Coba gratis 30 hari');
});

it('daftar membuat tenant trial, outlet, owner belum terverifikasi, metode bayar, lalu langsung masuk', function () {
    $this->freezeTime();

    $this->post('/daftar', signupBody())->assertRedirect('/dashboard');

    $tenant = Tenant::query()->sole();
    $owner = User::allTenants()->where('tenant_id', $tenant->id)->sole();

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->slug)->toBe('kopi-senja')
        ->and($tenant->subscription_ends_at->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->email)->toBe('budi@kopisenja.test')
        ->and($owner->hasVerifiedEmail())->toBeFalse()
        ->and($tenant->needsOwnerEmailVerification())->toBeTrue()
        ->and(TenantContext::run($tenant->id, fn () => Outlet::query()->sole()->code))->toBe('KOP01')
        ->and(TenantContext::run($tenant->id, fn () => PaymentMethod::query()->count()))->toBe(5);

    $this->assertAuthenticatedAs($owner, 'web');
    Notification::assertSentTo($owner, VerifyOwnerEmail::class);
});

it('slug unik bila nama bisnis sama', function () {
    $this->post('/daftar', signupBody());
    auth('web')->logout();
    $this->post('/daftar', signupBody(['email' => 'lain@kopisenja.test']));

    expect(Tenant::query()->pluck('slug')->all())->toHaveCount(2)
        ->and(Tenant::query()->where('slug', 'like', 'kopi-senja-%')->count())->toBe(1);
});

it('validasi daftar', function (array $override, string $field) {
    User::factory()->create(['email' => 'pakai@kopi.test']);

    $this->post('/daftar', signupBody($override))->assertSessionHasErrors($field);
    expect(Tenant::query()->count())->toBe(1); // hanya tenant milik user factory
})->with([
    'email terdaftar (beda huruf besar)' => [['email' => 'PAKAI@kopi.test'], 'email'],
    'kata sandi lemah' => [['password' => 'pendek', 'password_confirmation' => 'pendek'], 'password'],
    'konfirmasi beda' => [['password_confirmation' => 'lain12345'], 'password'],
    'nama bisnis kosong' => [['business_name' => ''], 'business_name'],
    'jenis usaha tidak dikenal' => [['business_type' => 'pabrik'], 'business_type'],
]);

it('pendaftaran ditutup admin: form diganti pesan, kiriman diabaikan', function () {
    app(SystemSettings::class)->setOnboarding(14, false);

    $this->get('/daftar')->assertOk()->assertSee('Pendaftaran sedang ditutup');
    $this->get('/')->assertDontSee('Coba gratis');
    $this->post('/daftar', signupBody())->assertRedirect('/daftar');

    expect(Tenant::query()->count())->toBe(0);
});

it('honeypot & batas 5 pendaftaran per jam per IP', function () {
    $this->post('/daftar', signupBody(['website' => 'http://spam.test']))->assertRedirect('/');
    expect(Tenant::query()->count())->toBe(0);

    // Kiriman bot juga dihitung: tersisa 4 dari batas 5 per jam
    foreach (range(1, 4) as $i) {
        auth('web')->logout();
        $this->post('/daftar', signupBody(['email' => "u{$i}@kopi.test"]))->assertRedirect();
    }

    $this->post('/daftar', signupBody(['email' => 'u5@kopi.test']))->assertStatus(429);
    expect(Tenant::query()->count())->toBe(4);
});

describe('verifikasi email', function () {
    beforeEach(function () {
        $this->owner = User::factory()->owner()->unverified()->create(['email' => 'budi@kopi.test']);
    });

    it('link bertanda tangan memverifikasi tanpa login', function () {
        $this->get(VerifyOwnerEmail::url($this->owner))->assertOk()->assertSee('Email terverifikasi');

        expect($this->owner->refresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('link diubah, tanpa tanda tangan, atau kedaluwarsa ditolak', function () {
        $url = VerifyOwnerEmail::url($this->owner);

        $this->get(str_replace(sha1('budi@kopi.test'), sha1('lain@kopi.test'), $url))->assertForbidden();
        $this->get("/verifikasi-email/{$this->owner->id}/".sha1('budi@kopi.test'))->assertForbidden();

        $this->travel(VerifyOwnerEmail::VALID_DAYS + 1)->days();
        $this->get($url)->assertForbidden();

        expect($this->owner->refresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('owner belum terverifikasi bisa kirim ulang link dari dashboard', function () {
        $this->actingAs($this->owner);

        $this->post('/dashboard/verifikasi-email/kirim-ulang')->assertRedirect();
        Notification::assertSentTo($this->owner, VerifyOwnerEmail::class);
    });

    it('banner verifikasi tampil di dashboard', function () {
        $this->actingAs($this->owner);

        $this->get('/dashboard')->assertOk()->assertSee('Verifikasi email owner untuk mulai bertransaksi');
    });
});
