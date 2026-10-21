<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use App\Models\User;
use App\Support\TenantAppearance;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel /dashboard: terapkan tampilan bisnis (Q128) — palet warna utama Filament & mode tampilan bawaan. Token CSS
 * gs.POS disisipkan render hook HEAD_END (TenantAppearance::css). Pilihan terang/gelap pengguna sendiri (menu akun,
 * tersimpan di browser) tetap diutamakan atas mode bawaan bisnis.
 */
final class ApplyTenantAppearance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User) {
            $tenant = $user->tenant;
            FilamentColor::register(['primary' => TenantAppearance::palette(TenantAppearance::hex($tenant))]);
            Filament::getCurrentPanel()?->defaultThemeMode(TenantAppearance::themeMode($tenant));
        }

        return $next($request);
    }
}
