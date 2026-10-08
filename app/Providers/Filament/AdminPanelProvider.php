<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Shared\Layout;
use App\Filament\Shared\Theme;
use App\Http\Middleware\Filament\EnsureAdminTwoFactorWhenRequired;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
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
            ->path('admin')
            ->authGuard('admin')
            ->login()
            ->profile(isSimple: false)
            // 2FA aplikasi authenticator. Route & middleware selalu terdaftar ("wajib"); keputusan
            // wajib/tidak diambil per request oleh middleware dari setelan Sistem → Keamanan
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()->brandName('gs.POS Admin')],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminTwoFactorWhenRequired::class)
            ->colors(Theme::colors())
            ->font('Inter')
            ->brandName('gs.POS Admin')
            ->brandLogo(fn () => view('filament.shared.brand-logo', ['tag' => 'Super Admin']))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/brand/favicon.svg'))
            ->darkMode()
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Pelanggan')->collapsible(false),
                NavigationGroup::make('Aplikasi')->collapsible(false),
                NavigationGroup::make('Sistem')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
            ]);
    }
}
