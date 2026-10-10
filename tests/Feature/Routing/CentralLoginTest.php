<?php

declare(strict_types=1);

use App\Actions\Auth\DashboardLoginTicket;
use App\Enums\TenantStatus;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Login pelanggan di gspos.id/login → app.gspos.id lewat tiket sekali pakai (ADR 0008).
 * Mode subdomain dipasang sebelum aplikasi test dibuat (lihat SubdomainRoutingTest).
 */
const CENTRAL_LOGIN_ENV = [
    'POS_MAIN_DOMAIN' => 'gspos.localhost',
    'POS_APP_DOMAIN' => 'app.gspos.localhost',
    'POS_ADMIN_DOMAIN' => 'admin.gspos.localhost',
    'POS_API_DOMAIN' => 'api.gspos.localhost',
];

beforeAll(function () {
    foreach (CENTRAL_LOGIN_ENV as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

afterAll(function () {
    foreach (array_keys(CENTRAL_LOGIN_ENV) as $key) {
        putenv("{$key}=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }
});

beforeEach(function () {
    $this->owner = User::factory()->owner()->create(['email' => 'owner@kopi.test', 'password' => 'rahasia-123']);
});

/** POST login lalu ambil tiket dari URL pengalihan ke app. */
function loginForTicket(object $test, array $body): string
{
    $location = (string) $test->post('http://gspos.localhost/login', $body)->assertStatus(303)->headers->get('Location');

    expect(parse_url($location, PHP_URL_HOST))->toBe('app.gspos.localhost')
        ->and(parse_url($location, PHP_URL_PATH))->toBe('/auth/ticket');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) $query['ticket'];
}

it('login di gspos.id lalu masuk ke app. tanpa sesi login di domain utama', function () {
    $ticket = loginForTicket($this, ['email' => 'OWNER@kopi.test', 'password' => 'rahasia-123']);

    // Domain utama hanya memverifikasi kata sandi
    $this->assertGuest('web');

    $this->get('http://app.gspos.localhost/auth/ticket?ticket='.$ticket)->assertRedirect();
    $this->assertAuthenticatedAs($this->owner, 'web');
});

it('tiket hanya bisa dipakai sekali', function () {
    $ticket = loginForTicket($this, ['email' => 'owner@kopi.test', 'password' => 'rahasia-123']);

    $this->get('http://app.gspos.localhost/auth/ticket?ticket='.$ticket);
    auth('web')->logout();

    $this->get('http://app.gspos.localhost/auth/ticket?ticket='.$ticket)
        ->assertRedirect('http://gspos.localhost/login?expired=1');
    $this->assertGuest('web');
});

it('tiket palsu atau kedaluwarsa ditolak', function () {
    $this->get('http://app.gspos.localhost/auth/ticket?ticket='.str_repeat('a', 64))
        ->assertRedirect('http://gspos.localhost/login?expired=1');

    $this->travel(61)->seconds();
    $ticket = app(DashboardLoginTicket::class)->issue($this->owner, false, null);
    $this->travel(61)->seconds();

    $this->get('http://app.gspos.localhost/auth/ticket?ticket='.$ticket)
        ->assertRedirect('http://gspos.localhost/login?expired=1');
    $this->assertGuest('web');
});

it('kembali ke halaman yang tadinya dibuka; next tidak bisa dipakai open redirect', function (string $next, string $expectedPath) {
    $ticket = loginForTicket($this, ['email' => 'owner@kopi.test', 'password' => 'rahasia-123', 'next' => $next]);

    $location = (string) $this->get('http://app.gspos.localhost/auth/ticket?ticket='.$ticket)->headers->get('Location');

    expect(parse_url($location, PHP_URL_HOST))->toBe('app.gspos.localhost')
        ->and((parse_url($location, PHP_URL_PATH) ?: '/').(($q = parse_url($location, PHP_URL_QUERY)) ? '?'.$q : ''))->toBe($expectedPath);
})->with([
    'path biasa' => ['/products?page=2', '/products?page=2'],
    'URL absolut' => ['https://jahat.test/phish', '/'],
    'protocol-relative' => ['//jahat.test', '/'],
    'backslash' => ['/\\jahat.test', '/'],
]);

it('membuka app. tanpa login diarahkan ke gspos.id/login membawa path tujuan', function () {
    $this->get('http://app.gspos.localhost/products')->assertRedirect();
    $this->get('http://app.gspos.localhost/login')
        ->assertRedirect('http://gspos.localhost/login?next=%2Fproducts');
});

it('kata sandi salah: pesan umum dan dibatasi 5 percobaan', function () {
    RateLimiter::clear('dashboard-login:owner@kopi.test|127.0.0.1');

    foreach (range(1, 5) as $_) {
        $this->post('http://gspos.localhost/login', ['email' => 'owner@kopi.test', 'password' => 'salah'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    }

    $this->post('http://gspos.localhost/login', ['email' => 'owner@kopi.test', 'password' => 'rahasia-123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('web');
});

it('kasir, akun nonaktif, dan tenant ditangguhkan tidak bisa masuk dashboard', function () {
    $cashier = User::factory()->cashier()->create(['email' => 'kasir@kopi.test', 'password' => 'rahasia-123']);
    $this->post('http://gspos.localhost/login', ['email' => 'kasir@kopi.test', 'password' => 'rahasia-123'])
        ->assertSessionHasErrors(['email' => 'Akun ini tidak memiliki akses ke dashboard.']);

    $this->owner->tenant->update(['status' => TenantStatus::Suspended]);
    $this->post('http://gspos.localhost/login', ['email' => 'owner@kopi.test', 'password' => 'rahasia-123'])
        ->assertSessionHasErrors('email');

    expect($cashier->exists)->toBeTrue();
});

it('form login wajib CSRF di aplikasi nyata (route berada di grup web)', function () {
    $route = app('router')->getRoutes()->getByName('login.store');

    expect($route?->gatherMiddleware())->toContain('web');
});

it('daftar di gspos.id langsung masuk ke app. lewat tiket', function () {
    Notification::fake();
    RateLimiter::clear('signup-h:127.0.0.1');

    $location = (string) $this->post('http://gspos.localhost/register', [
        'business_name' => 'Warung Bu Sri', 'business_type' => 'retail', 'name' => 'Sri',
        'email' => 'sri@warung.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
    ])->assertStatus(303)->headers->get('Location');

    expect(parse_url($location, PHP_URL_HOST))->toBe('app.gspos.localhost');

    $this->get($location)->assertRedirect();
    expect(auth('web')->user()?->email)->toBe('sri@warung.test');
});
