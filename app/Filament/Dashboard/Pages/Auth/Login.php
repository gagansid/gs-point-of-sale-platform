<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\Auth\DashboardLoginTicket;
use App\Filament\Shared\Auth\Login as SharedLogin;
use App\Http\Controllers\Web\DashboardLoginController;
use App\Services\Security\Turnstile;
use App\Support\Domains;

/**
 * Halaman login panel pelanggan. Dengan subdomain (ADR 0008) login ada di gspos.id/login:
 * app.gspos.id/login hanya mengalihkan ke sana, membawa path yang tadinya ingin dibuka.
 * Tanpa subdomain tetap memakai form login Filament (tampilan & Turnstile owner dari Shared\Auth\Login).
 */
final class Login extends SharedLogin
{
    public static function turnstileAudience(): string
    {
        return Turnstile::OWNER;
    }

    public function authBrand(): array
    {
        return [
            'subheading' => 'Dashboard owner & manager',
            'app' => 'Dashboard',
            'heading' => 'Kelola bisnis dari mana saja',
            'text' => 'Pantau penjualan, stok, dan karyawan semua outlet dalam satu dashboard.',
            'points' => [['chart', 'Laporan penjualan real-time'], ['cube', 'Stok & bahan baku terkendali'], ['users', 'Karyawan, shift, dan hak akses']],
        ];
    }

    public function mount(): void
    {
        if (! Domains::enabled()) {
            parent::mount();

            return;
        }

        $intended = session()->pull('url.intended');
        $next = is_string($intended) ? DashboardLoginTicket::safePath(parse_url($intended, PHP_URL_PATH).(($query = parse_url($intended, PHP_URL_QUERY)) ? '?'.$query : '')) : null;

        $this->redirect(DashboardLoginController::centralLoginUrl($next !== null && $next !== '/' ? ['next' => $next] : []));
    }
}
