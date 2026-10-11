<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
 * GSPOS-SEC-005 (docs/security/reports/2026-10-10-audit.md): login gspos.id/login tidak boleh menjadi
 * alat menebak kata sandi kasir/supervisor (kasir web). Akun tanpa akses dashboard dijawab sama
 * dengan kata sandi salah dan tetap terhitung throttle.
 */
const SEC005_SUBDOMAIN_ENV = [
    'POS_MAIN_DOMAIN' => 'gspos.localhost',
    'POS_APP_DOMAIN' => 'app.gspos.localhost',
    'POS_ADMIN_DOMAIN' => 'admin.gspos.localhost',
    'POS_API_DOMAIN' => 'api.gspos.localhost',
];

beforeAll(function () {
    foreach (SEC005_SUBDOMAIN_ENV as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

afterAll(function () {
    foreach (array_keys(SEC005_SUBDOMAIN_ENV) as $key) {
        putenv("{$key}=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }
});

beforeEach(function () {
    $outlet = Outlet::factory()->create();
    User::factory()->cashier()->forOutlet($outlet)->create(['email' => 'kasir@kopi.test', 'password' => 'rahasia-123']);
    User::factory()->owner()->create(['tenant_id' => $outlet->tenant_id, 'email' => 'mantan@kopi.test', 'password' => 'rahasia-123', 'is_active' => false]);
    RateLimiter::clear('dashboard-login:kasir@kopi.test|127.0.0.1');
});

it('kata sandi kasir benar dan salah dijawab dengan pesan yang sama', function (string $password) {
    $this->post('http://gspos.localhost/login', ['email' => 'kasir@kopi.test', 'password' => $password])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
})->with(['benar' => 'rahasia-123', 'salah' => 'salah-sekali']);

it('owner nonaktif dengan kata sandi benar dijawab pesan umum', function () {
    $this->post('http://gspos.localhost/login', ['email' => 'mantan@kopi.test', 'password' => 'rahasia-123'])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
});

it('kata sandi kasir yang benar tetap terhitung throttle (tidak me-reset percobaan)', function () {
    foreach (range(1, 4) as $_) {
        $this->post('http://gspos.localhost/login', ['email' => 'kasir@kopi.test', 'password' => 'salah-sekali']);
    }

    // Tebakan ke-5 benar: dulu throttle di-reset di sini
    $this->post('http://gspos.localhost/login', ['email' => 'kasir@kopi.test', 'password' => 'rahasia-123']);

    $this->post('http://gspos.localhost/login', ['email' => 'kasir@kopi.test', 'password' => 'tebakan-lain'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toStartWith('Terlalu banyak percobaan');
});
