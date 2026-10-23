<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Shared\FieldDefaults;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Theme;
use App\Http\Middleware\Filament\EnsureAdminTwoFactorWhenRequired;
use App\Models\Admin;
use App\Support\AdminAlerts;
use App\Support\Domains;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel /admin — super admin (pengelola sistem). Guard & tabel terpisah dari tenant.
 * Tampilan mengikuti docs/standards/ui/ (token di resources/css/filament/shared).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return Layout::apply($panel)
            ->default()
            ->id('admin')
            // Select & tanggal seragam (JS Filament, ikon sama), zona waktu platform
            ->bootUsing(fn () => FieldDefaults::apply())
            ->domain(Domains::admin())
            ->path(Domains::adminPath())
            ->authGuard('admin')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            // 2FA aplikasi authenticator. Route & middleware selalu terdaftar ("wajib"); keputusan
            // wajib/tidak diambil per request oleh middleware dari setelan Pengaturan → Keamanan
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()->brandName('gs.POS Admin')],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminTwoFactorWhenRequired::class)
            ->colors(Theme::colors())
            ->font('Plus Jakarta Sans')
            ->brandName('gs.POS Admin')
            ->brandLogo(fn () => view('filament.shared.brand-logo', ['tag' => 'Super Admin']))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/brand/favicon.svg'))
            // Bawaan selalu terang; gelap hanya bila pengguna memilihnya di menu akun (tersimpan di browser)
            ->darkMode()
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                // Bisnis pelanggan & calon pelanggan
                NavigationGroup::make('Pelanggan')->collapsible(false),
                // Yang dijual (paket, add-on), tagihan, dan cara bayar langganan
                NavigationGroup::make('Langganan')->collapsible(false),
                NavigationGroup::make('Aplikasi')->collapsible(false),
                // Setelan platform & akun admin
                NavigationGroup::make('Pengaturan')->collapsible(false),
                // Operasional teknis: backup, antrean, pemeliharaan, log, audit
                NavigationGroup::make('Sistem')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
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
            // Lonceng peringatan di kiri tombol tema (Q135): bukti transfer, tagihan lewat jatuh tempo, calon pelanggan baru,
            // job gagal, backup usang, mode pemeliharaan — tampilan sama dengan panel /dashboard
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Filament::auth()->user() instanceof Admin
                ? view('filament.shared.alerts', ['alerts' => AdminAlerts::all()])->render()
                : '');
    }
}
