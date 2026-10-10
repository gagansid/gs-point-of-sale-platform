<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\Auth\DashboardLoginTicket;
use App\Http\Controllers\Web\DashboardLoginController;
use App\Support\Domains;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Halaman login panel pelanggan. Dengan subdomain (ADR 0008) login ada di gspos.id/login:
 * app.gspos.id/login hanya mengalihkan ke sana, membawa path yang tadinya ingin dibuka.
 * Tanpa subdomain tetap memakai form login Filament.
 */
final class Login extends BaseLogin
{
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
