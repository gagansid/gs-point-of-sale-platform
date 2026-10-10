<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Dashboard\Pages\Auth\Login;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Pages\Home;
use App\Filament\Shared\Theme;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\SwitchOutletController;
use App\Http\Middleware\Filament\SetDashboardTenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Domains;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel /dashboard — owner & manager satu bisnis (SPEC Panel dashboard owner).
 * Isolasi tenant memakai TenantContext + TenantScope, bukan fitur tenancy Filament (ADR 0007).
 */
final class DashboardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return Layout::apply($panel)
            ->id('dashboard')
            ->domain(Domains::app())
            ->path(Domains::appPath())
            ->authGuard('web')
            // Dengan subdomain: mengalihkan ke gspos.id/login (ADR 0008)
            ->login(Login::class)
            ->profile(isSimple: false)
            ->colors(Theme::colors())
            ->font('Plus Jakarta Sans')
            ->brandName('gs.POS')
            ->brandLogo(fn () => view('filament.shared.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/brand/favicon.svg'))
            ->darkMode()
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/dashboard/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Transaksi')->collapsible(false),
                NavigationGroup::make('Katalog')->collapsible(false),
                NavigationGroup::make('Laporan')->collapsible(false),
                NavigationGroup::make('Pengaturan')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\Filament\Dashboard\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\Filament\Dashboard\Pages')
            ->pages([
                Home::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\Filament\Dashboard\Widgets')
            // Widget beranda ditemukan otomatis dari app/Filament/Dashboard/Widgets
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Kirim ulang link verifikasi email owner (ADR 0009); route di dalam panel = wajib login
            ->authenticatedRoutes(function (): void {
                Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
                    ->middleware('throttle:3,10')
                    ->name('verification.send');
                // Pemilih outlet topbar (ADR 0010)
                Route::post('switch-outlet/{outlet}', SwitchOutletController::class)
                    ->where('outlet', 'all|[0-9a-fA-F-]{36}')
                    ->name('switch-outlet');
            })
            // Pemilih outlet di topbar: hanya bila user memegang lebih dari satu outlet aktif (ADR 0010)
            ->renderHook(PanelsRenderHook::TOPBAR_START, function (): string {
                $user = Filament::auth()->user();

                if (! $user instanceof User) {
                    return '';
                }

                $outlets = $user->accessibleOutlets()->active()->get(['id', 'name']);

                return $outlets->count() > 1
                    ? view('filament.dashboard.outlet-switcher', ['outlets' => $outlets, 'selectedId' => CurrentOutlet::selectedId()])->render()
                    : '';
            })
            // Banner trial / hanya-baca di atas setiap halaman (ADR 0009)
            ->renderHook(PanelsRenderHook::CONTENT_START, function (): string {
                $user = Filament::auth()->user();

                return $user instanceof User ? view('filament.dashboard.subscription-banner', ['tenant' => $user->tenant])->render() : '';
            })
            // Persistent: juga berjalan di request Livewire (aksi tabel, simpan form)
            ->authMiddleware([
                SetDashboardTenant::class,
            ], isPersistent: true);
    }
}
