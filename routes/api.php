<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

/*
 * Prefix /api dan grup "api" (ForceJsonResponse, throttle:api) diatur di bootstrap/app.php.
 * Route dikelompokkan per domain, urut sesuai tabel API di docs/SPEC.md.
 *
 * Grup akses:
 *   publik          tanpa token
 *   token.device    device token (layar PIN, ADR 0002)
 *   token.user      token user (login email/PIN)
 */
Route::prefix('v1')->name('api.v1.')->middleware('app.version')->group(function (): void {

    // ---------- Auth & sistem: publik ----------
    Route::get('system/status', [SystemController::class, 'status'])
        ->withoutMiddleware('app.version')
        ->name('system.status');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');

    // ---------- Auth & sistem: device token ----------
    Route::middleware(['auth:sanctum', 'tenant', 'token.device', 'tenant.active'])->group(function (): void {
        Route::get('devices/{deviceUid}/cashiers', [DeviceController::class, 'cashiers'])->name('devices.cashiers');
        Route::post('auth/pin-login', [AuthController::class, 'pinLogin'])
            ->middleware('throttle:auth')
            ->name('auth.pin-login');
    });

    // ---------- Token user ----------
    Route::middleware(['auth:sanctum', 'tenant', 'token.user', 'tenant.active'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    });
});
