<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use App\Support\SystemSettings;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Pengganti middleware wajib-2FA Filament yang membaca setelan Sistem → Keamanan SETIAP request.
 *
 * Filament menentukan route & middleware 2FA saat registrasi route; dengan route cache
 * (php artisan optimize) perubahan setelan tidak akan berlaku. Karena itu panel selalu
 * didaftarkan "wajib", dan keputusan sebenarnya diambil di sini.
 */
final class EnsureAdminTwoFactorWhenRequired extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function __construct(private readonly SystemSettings $settings) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->settings->adminTwoFactorRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
