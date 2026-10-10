<?php

declare(strict_types=1);

use App\Http\Controllers\Web\DashboardLoginController;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\SignupController;
use App\Http\Controllers\Web\SiteController;
use App\Support\Domains;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Domain utama (gspos.id): halaman depan, form hubungi sales, dan login pelanggan.
 * Panel & API memakai subdomain sendiri bila diatur (ADR 0008): app.gspos.id, admin.gspos.id,
 * api.gspos.id/v1. Tanpa subdomain, semua di satu domain dan login memakai /dashboard/login.
 */
Route::domain(Domains::main())->group(function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('landing');
    Route::post('contact-sales', [SiteController::class, 'contact'])
        ->middleware('throttle:contact')
        ->name('contact');

    // Lupa kata sandi & undangan owner (SPEC Q34)
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');

    // Daftar mandiri + verifikasi email (ADR 0009)
    Route::get('register', [SignupController::class, 'create'])->name('signup');
    Route::post('register', [SignupController::class, 'store'])->middleware('throttle:signup')->name('signup.store');
    Route::get('email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    if (! Domains::enabled()) {
        return;
    }

    Route::get('login', [DashboardLoginController::class, 'create'])->name('login');
    Route::post('login', [DashboardLoginController::class, 'store'])->name('login.store');

    /*
     * URL lama → subdomain baru, path & query dipertahankan:
     *   /dashboard/products → app.gspos.id/products
     *   /admin/tenants      → admin.gspos.id/tenants
     *   /api/v1/catalog     → api.gspos.id/v1/catalog (308: metode & body POST ikut)
     */
    $move = fn (string $domain, string $prefix, int $status) => fn (Request $request, ?string $path = null): RedirectResponse => redirect()->away(
        Domains::url($domain, $prefix.($path ?? ''), $request->getQueryString()),
        $status,
    );

    // Hanya mengalihkan (tanpa membaca/mengubah data), jadi tanpa CSRF: POST dari aplikasi lama
    // tanpa token tetap dialihkan, bukan ditolak 419
    Route::withoutMiddleware(ValidateCsrfToken::class)->group(function () use ($move): void {
        Route::any('dashboard/{path?}', $move((string) Domains::app(), '', 301))->where('path', '.*');
        Route::any('admin/{path?}', $move((string) Domains::admin(), '', 301))->where('path', '.*');
        Route::any('api/v1/{path?}', $move((string) Domains::api(), 'v1/', 308))->where('path', '.*');
    });
});

// app.gspos.id: tukar tiket login dari gspos.id/login menjadi sesi (ADR 0008)
if (Domains::enabled()) {
    Route::domain(Domains::app())
        ->get('auth/ticket', [DashboardLoginController::class, 'redeem'])
        ->middleware('throttle:auth')
        ->name('dashboard.login.ticket');
}
