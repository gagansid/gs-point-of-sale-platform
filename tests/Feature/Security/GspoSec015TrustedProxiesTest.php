<?php

declare(strict_types=1);

use App\Support\Domains;
use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * GSPOS-SEC-015 (docs/security/reports/2026-10-10-audit.md): di belakang proxy tepercaya
 * (POS_TRUSTED_PROXIES) IP asli & https dibaca dari X-Forwarded-*; dari klien biasa header itu
 * diabaikan sehingga rate limit per IP tidak bisa diakali/dibagi.
 */
beforeEach(function () {
    Route::get('/_test/client', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
    ]);
});

afterEach(function () {
    TrustProxies::flushState();
    Request::setTrustedProxies([], -1);
});

it('membaca POS_TRUSTED_PROXIES: kosong, cloudflare, IP/CIDR; entri rusak diabaikan', function () {
    expect(TrustedProxies::fromConfig(''))->toBe([])
        ->and(TrustedProxies::fromConfig('cloudflare'))->toContain('173.245.48.0/20', '2606:4700::/32')
        ->and(TrustedProxies::fromConfig('10.0.0.5, 192.168.0.0/16, ::1'))->toBe(['10.0.0.5', '192.168.0.0/16', '::1'])
        ->and(TrustedProxies::fromConfig('*, bukan-ip, 10.0.0.0/33'))->toBe([]);
});

it('request dari Cloudflare: IP asli & https dipakai, X-Forwarded-Host diabaikan', function () {
    TrustProxies::at(TrustedProxies::fromConfig('cloudflare'));
    TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

    $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
        ->getJson('/_test/client', ['X-Forwarded-For' => '36.85.1.2', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test'])
        ->assertOk()
        ->assertJson(['ip' => '36.85.1.2', 'secure' => true, 'host' => 'localhost']);
});

it('klien biasa memalsukan X-Forwarded-For → diabaikan', function () {
    TrustProxies::at(TrustedProxies::fromConfig('cloudflare'));
    TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

    $this->withServerVariables(['REMOTE_ADDR' => '36.85.9.9'])
        ->getJson('/_test/client', ['X-Forwarded-For' => '1.1.1.1', 'X-Forwarded-Proto' => 'https'])
        ->assertJson(['ip' => '36.85.9.9', 'secure' => false]);
});

it('tanpa konfigurasi (default) tidak ada proxy yang dipercaya', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
        ->getJson('/_test/client', ['X-Forwarded-For' => '36.85.1.2'])
        ->assertJson(['ip' => '173.245.48.10']);
});

it('URL antar subdomain di production selalu https tanpa port :80', function () {
    $this->app->detectEnvironment(fn () => 'production');
    $this->app->instance('request', Request::create('http://gspos.id/login'));

    expect(Domains::url('app.gspos.id', 'auth/ticket', 'ticket=x'))->toBe('https://app.gspos.id/auth/ticket?ticket=x');
});

it('URL antar subdomain di local mempertahankan skema & port dev', function () {
    $this->app->instance('request', Request::create('http://gspos.localhost:8000/login'));

    expect(Domains::url('app.gspos.localhost', 'auth/ticket'))->toBe('http://app.gspos.localhost:8000/auth/ticket');
});
