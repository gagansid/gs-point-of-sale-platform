<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
 * GSPOS-SEC-009 (docs/security/reports/2026-10-10-audit.md): di mode subdomain produksi
 * (api.gspos.id/v1, ADR 0008) semua error API tetap memakai envelope ApiResponse dan tidak
 * pernah membocorkan stack trace, termasuk saat APP_DEBUG=true.
 */
const SEC009_SUBDOMAIN_ENV = [
    'POS_MAIN_DOMAIN' => 'gspos.localhost',
    'POS_APP_DOMAIN' => 'app.gspos.localhost',
    'POS_ADMIN_DOMAIN' => 'admin.gspos.localhost',
    'POS_API_DOMAIN' => 'api.gspos.localhost',
];

beforeAll(function () {
    foreach (SEC009_SUBDOMAIN_ENV as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

afterAll(function () {
    foreach (array_keys(SEC009_SUBDOMAIN_ENV) as $key) {
        putenv("{$key}=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }
});

it('BusinessException (APP_UPDATE_REQUIRED) dirender sebagai envelope 426, bukan 500', function () {
    // Tanpa header X-App-Version → CheckAppVersion melempar BusinessException
    $response = $this->postJson('http://api.gspos.localhost/v1/auth/login', [], ['Accept' => 'application/json']);

    assertApiError($response, 'APP_UPDATE_REQUIRED', 426);
});

it('route tidak dikenal → envelope NOT_FOUND', function () {
    assertApiError($this->getJson('http://api.gspos.localhost/v1/tidak-ada', apiHeaders()), 'NOT_FOUND', 404);
});

it('tanpa token → envelope UNAUTHENTICATED', function () {
    assertApiError($this->getJson('http://api.gspos.localhost/v1/auth/me', apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('APP_DEBUG=true tidak membocorkan stack trace di API subdomain', function () {
    Config::set('app.debug', true);

    $this->getJson('http://api.gspos.localhost/v1/tidak-ada', apiHeaders())
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('file')
        ->assertJsonMissingPath('exception');
});
