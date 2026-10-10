<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\OptionGroupController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Domain/prefix (api.gspos.id/v1 atau /api/v1) dan grup "api" (ForceJsonResponse, throttle:api)
 * diatur di bootstrap/app.php (ADR 0008).
 * Route dikelompokkan per domain, urut sesuai tabel API di docs/SPEC.md.
 *
 * Grup akses:
 *   publik          tanpa token
 *   token.device    device token (layar PIN, ADR 0002)
 *   token.user      token user (login email/PIN)
 */
Route::name('api.v1.')->middleware('app.version')->group(function (): void {

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

        // ---------- Katalog & produk ----------
        Route::get('catalog', [CatalogController::class, 'index'])->name('catalog');

        Route::controller(ProductController::class)->prefix('products')->name('products.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('barcode/{code}', 'barcode')->where('code', '[A-Za-z0-9-]{1,50}')->name('barcode');
            Route::post('/', 'store')->name('store');
            Route::put('{product}', 'update')->name('update');
            Route::delete('{product}', 'destroy')->name('destroy');
            Route::post('{product}/stock', 'adjustStock')->name('stock');
            Route::patch('{product}/availability', 'availability')->name('availability');
        });

        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('option-groups', OptionGroupController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['option-groups' => 'optionGroup']);

        // ---------- Shift ----------
        Route::controller(ShiftController::class)->prefix('shifts')->name('shifts.')->group(function (): void {
            Route::get('current', 'current')->name('current');
            Route::post('/', 'store')->name('store');
            Route::post('{shift}/close', 'close')->name('close');
            Route::get('{shift}/summary', 'summary')->name('summary');
            Route::post('{shift}/force-close', 'forceClose')->name('force-close');
        });

        // ---------- Order & pembayaran ----------
        Route::post('checkout', [OrderController::class, 'checkout'])->name('checkout');

        Route::controller(OrderController::class)->prefix('orders')->name('orders.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            // {orderId} bukan route model binding: PUT juga membuat open bill baru
            Route::put('{orderId}', 'saveOpenBill')->whereUuid('orderId')->name('save');
            Route::get('{order}', 'show')->name('show');
            Route::post('{order}/payments', 'addPayment')->name('payments.store');
            Route::get('{order}/receipt', 'receipt')->name('receipt');
            Route::post('{order}/void', 'void')->name('void');
        });

        // ---------- Laporan ----------
        Route::controller(ReportController::class)->prefix('reports')->name('reports.')->group(function (): void {
            Route::get('summary', 'summary')->name('summary');
            Route::get('products', 'products')->name('products');
            Route::get('payment-methods', 'paymentMethods')->name('payment-methods');
        });

        // ---------- Setelan ----------
        Route::controller(OutletController::class)->prefix('outlet')->name('outlet.')->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::put('/', 'update')->name('update');
        });

        Route::controller(UserController::class)->prefix('users')->name('users.')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('{user}', 'show')->name('show');
            Route::put('{user}', 'update')->name('update');
            Route::post('{user}/unlock-pin', 'unlockPin')->name('unlock-pin');
        });
    });
});
