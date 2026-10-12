<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Filament\Dashboard\Pages\Auth\Login;
use App\Filament\Dashboard\Pages\Home;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Theme;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\ReceiptController;
use App\Http\Controllers\Web\SwitchOutletController;
use App\Http\Controllers\Web\TableQrController;
use App\Http\Middleware\Filament\SetDashboardTenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Domains;
use App\Support\Support;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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
            ->profile(EditProfile::class, isSimple: false)
            // Menu akun: profil (bawaan), keamanan, perangkat, bantuan; tema dipindah ke samping nama (user-menu override)
            ->userMenuItems([
                // Menu akun hanya Profil, Bantuan, Keluar (SPEC Q84)
                'profile' => fn (Action $action): Action => $action->label('Profil')->icon(Heroicon::OutlinedUserCircle),
                'help' => Action::make('help')->label('Bantuan')->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->url(fn (): string => Support::helpUrl(), shouldOpenInNewTab: true)->sort(1),
            ])
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
                // Stok masuk, supplier, opname, transfer (ADR 0015)
                NavigationGroup::make('Stok')->collapsible(false),
                NavigationGroup::make('Pelanggan')->collapsible(false),
                NavigationGroup::make('Laporan')->collapsible(false),
                NavigationGroup::make('Pengaturan')->collapsible(false),
            ])
            // Menu Kasir (Q55): tab baru ke kasir web, masuk ulang dengan kata sandi atau PIN
            ->navigationItems([
                NavigationItem::make('Kasir')
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->group('Transaksi')
                    ->sort(0)
                    ->url(fn (): string => route('cashier.confirm'), shouldOpenInNewTab: true)
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        return $user instanceof User && $user->canUseWebCashier();
                    }),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\Filament\Dashboard\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\Filament\Dashboard\Pages')
            ->pages([
                Home::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\Filament\Dashboard\Widgets')
            // Urutan widget beranda diatur di Pages\Home::getWidgets()
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
                // Cetak ulang struk (T3); id dicari lewat query tenant-scoped di controller
                Route::get('orders/{order}/receipt', ReceiptController::class)
                    ->where('order', '[0-9a-fA-F-]{36}')
                    ->name('orders.receipt');
                // Cetak QR meja self-order (ADR 0014)
                Route::get('tables/print', TableQrController::class)->name('tables.print');
                // Pemilih outlet sidebar (ADR 0010)
                Route::post('switch-outlet/{outlet}', SwitchOutletController::class)
                    ->where('outlet', 'all|[0-9a-fA-F-]{36}')
                    ->name('switch-outlet');
            })
            // Pemilih outlet di atas menu sidebar (seperti workspace): hanya bila user memegang lebih dari
            // satu outlet aktif (ADR 0010). Topbar tetap bersih: hamburger + pencarian di kiri.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, function (): string {
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

                return $user instanceof User
                    ? view('filament.dashboard.subscription-banner', ['tenant' => $user->tenant])->render()
                        // Pengumuman super admin ke semua tenant
                        .view('filament.dashboard.announcements')->render()
                    : '';
            })
            // Persistent: juga berjalan di request Livewire (aksi tabel, simpan form)
            ->authMiddleware([
                SetDashboardTenant::class,
            ], isPersistent: true);
    }
}
