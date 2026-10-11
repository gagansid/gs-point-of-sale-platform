<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Cashier\ConfirmController as CashierConfirmController;
use App\Http\Controllers\Web\Cashier\HomeController as CashierHomeController;
use App\Http\Controllers\Web\Cashier\LoginController as CashierLoginController;
use App\Http\Controllers\Web\Cashier\OrderController as CashierOrderController;
use App\Http\Controllers\Web\Cashier\OutletController as CashierOutletController;
use App\Http\Controllers\Web\Cashier\ShiftController as CashierShiftController;
use App\Http\Controllers\Web\DashboardLoginController;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\Member\AuthController as MemberAuthController;
use App\Http\Controllers\Web\Member\PortalController as MemberPortalController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\SignupController;
use App\Http\Controllers\Web\SiteController;
use App\Http\Middleware\Cashier\SetCashierContext;
use App\Http\Middleware\Member\SetMemberPortal;
use App\Support\Domains;
use App\Support\Edition;
use Filament\Facades\Filament;
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
    if (Edition::isSelfHosted()) {
        // Jual putus: tanpa halaman penjualan, daftar mandiri, maupun hubungi sales (ADR 0009)
        Route::get('/', fn (): RedirectResponse => redirect()->to(
            Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl(),
        ))->name('landing');
    } else {
        Route::get('/', [SiteController::class, 'home'])->name('landing');
        Route::post('contact-sales', [SiteController::class, 'contact'])
            ->middleware('throttle:contact')
            ->name('contact');
    }

    // Lupa kata sandi & undangan owner (SPEC Q34)
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');

    // Daftar mandiri (hanya SaaS) + verifikasi email (ADR 0009)
    if (Edition::isSaas()) {
        Route::get('register', [SignupController::class, 'create'])->name('signup');
        Route::post('register', [SignupController::class, 'store'])->middleware('throttle:signup')->name('signup.store');
    }
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
        Route::any('cashier/{path?}', $move((string) Domains::app(), 'cashier/', 301))->where('path', '.*');
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

/*
 * Halaman member (ADR 0013, Q67): gspos.id/m/{kode-bisnis}, atau /m/{kode-bisnis} tanpa subdomain. Guard "member",
 * tenant dari kode bisnis (SetMemberPortal).
 */
Route::domain(Domains::main())->prefix('m/{business}')->name('member.')
    ->where(['business' => '[a-z0-9-]+'])
    ->group(function (): void {
        Route::middleware(SetMemberPortal::class)->group(function (): void {
            Route::get('login', [MemberAuthController::class, 'create'])->name('login');
            Route::post('login', [MemberAuthController::class, 'store'])->name('login.store');
            Route::get('register', [MemberAuthController::class, 'registerForm'])->name('register');
            Route::post('register', [MemberAuthController::class, 'register'])->name('register.store');
        });

        Route::middleware(SetMemberPortal::class.':auth')->group(function (): void {
            Route::get('/', [MemberPortalController::class, 'home'])->name('home');
            Route::get('code', [MemberPortalController::class, 'code'])->name('code');
            Route::get('history', [MemberPortalController::class, 'history'])->name('history');
            Route::get('profile', [MemberPortalController::class, 'profile'])->name('profile');
            Route::post('profile', [MemberPortalController::class, 'updateProfile'])->name('profile.update');
            Route::get('password', [MemberPortalController::class, 'editPassword'])->name('password.edit');
            Route::post('password', [MemberPortalController::class, 'updatePassword'])->name('password.update');
            Route::post('delete', [MemberPortalController::class, 'destroy'])->name('destroy');
            Route::post('logout', [MemberAuthController::class, 'destroy'])->name('logout');
        });
    });

/*
 * Kasir web (Q54): app.gspos.id/cashier, atau /cashier tanpa subdomain. Guard "cashier" terpisah
 * dari /dashboard; browser = device web per outlet.
 */
Route::domain(Domains::app())->prefix('cashier')->name('cashier.')->group(function (): void {
    Route::get('login', [CashierLoginController::class, 'create'])->name('login');
    Route::post('login', [CashierLoginController::class, 'store'])->name('login.store');
    Route::post('logout', [CashierLoginController::class, 'destroy'])->name('logout');
    // Menu Kasir di dashboard (Q55): masuk ulang dengan kata sandi atau PIN
    Route::get('confirm', [CashierConfirmController::class, 'create'])->name('confirm');
    Route::post('confirm', [CashierConfirmController::class, 'store'])->name('confirm.store');

    Route::middleware(SetCashierContext::class.':outlet')->group(function (): void {
        Route::get('outlet', [CashierOutletController::class, 'index'])->name('outlet');
        Route::post('outlet', [CashierOutletController::class, 'store'])->name('outlet.store');
    });

    Route::middleware(SetCashierContext::class)->group(function (): void {
        Route::get('/', CashierHomeController::class)->name('home');
        Route::get('orders', [CashierOrderController::class, 'index'])->name('orders');
        Route::get('orders/{order}', [CashierOrderController::class, 'show'])->whereUuid('order')->name('orders.show');
        Route::post('orders/{order}/void', [CashierOrderController::class, 'void'])->whereUuid('order')->name('orders.void');
        Route::get('orders/{order}/receipt', [CashierOrderController::class, 'receipt'])->whereUuid('order')->name('orders.receipt');

        Route::get('shift', [CashierShiftController::class, 'show'])->name('shift');
        Route::post('shift', [CashierShiftController::class, 'store'])->name('shift.store');
        Route::post('shift/close', [CashierShiftController::class, 'close'])->name('shift.close');
        Route::get('shifts/{shift}', [CashierShiftController::class, 'recap'])->whereUuid('shift')->name('shifts.show');
        Route::get('shifts/{shift}/pdf', [CashierShiftController::class, 'pdf'])->whereUuid('shift')->name('shifts.pdf');
    });
});
