<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Prefix /api dan middleware grup "api" (ForceJsonResponse, throttle:api) diatur di bootstrap/app.php.
 * Route dikelompokkan per domain, urut sesuai tabel API di docs/SPEC.md.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Endpoint ditambahkan per domain mulai langkah Auth & sistem
});
