<?php

declare(strict_types=1);

use App\Support\Domains;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/*
 * GSPOS-SEC-016 (docs/security/reports/2026-10-10-audit.md): host header asing ditolak agar link
 * reset kata sandi / verifikasi email tidak mengarah ke domain penyerang. TrustHosts sendiri tidak
 * aktif saat test (runningUnitTests), jadi yang diuji daftar host-nya dan efeknya pada Request.
 */
afterEach(fn () => Request::setTrustedHosts([]));

it('mode satu domain: hanya host APP_URL yang dipercaya', function () {
    config(['app.url' => 'https://pos.kopi.test']);

    $patterns = app(TrustHosts::class)->hosts();
    Request::setTrustedHosts($patterns);

    expect(Request::create('https://pos.kopi.test/forgot-password')->getHost())->toBe('pos.kopi.test');
    expect(fn () => Request::create('https://evil.test/forgot-password')->getHost())->toThrow(SuspiciousOperationException::class);
    expect(fn () => Request::create('https://pos.kopi.test.evil.test/')->getHost())->toThrow(SuspiciousOperationException::class);
});

it('mode subdomain: domain utama, app, admin, dan api dipercaya', function () {
    config([
        'app.url' => 'https://gspos.id',
        'pos.domains.main' => 'gspos.id',
        'pos.domains.app' => 'app.gspos.id',
        'pos.domains.admin' => 'admin.gspos.id',
        'pos.domains.api' => 'api.gspos.id',
    ]);

    Request::setTrustedHosts(Domains::trustedHostPatterns());

    foreach (['gspos.id', 'app.gspos.id', 'admin.gspos.id', 'api.gspos.id'] as $host) {
        expect(Request::create("https://{$host}/")->getHost())->toBe($host);
    }
    expect(fn () => Request::create('https://evil-gspos.id/')->getHost())->toThrow(SuspiciousOperationException::class);
});

it('middleware TrustHosts terpasang secara global', function () {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue();
});
