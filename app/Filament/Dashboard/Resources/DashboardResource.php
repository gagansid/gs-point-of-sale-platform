<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources;

use App\Models\User;
use Filament\Resources\Resource;

/**
 * Dasar semua menu Resource panel /dashboard (ADR 0026): satu tempat untuk gerbang akses panel penuh
 * (`dashboard.access`). canViewAny() final = gerbang + canUseMenu() (permission menu), sehingga menu baru tidak bisa
 * lupa memeriksa akses dashboard. Dijaga test DashboardAccessGateTest.
 */
abstract class DashboardResource extends Resource
{
    /**
     * false = menu juga untuk pengguna tanpa dashboard.access yang punya permission menu (Stok masuk untuk supervisor,
     * Q79). Pengecualian harus disebut di ADR 0026.
     */
    protected static bool $requiresFullDashboard = true;

    final public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || (static::$requiresFullDashboard && ! $user->hasFullDashboard())) {
            return false;
        }

        return static::canUseMenu();
    }

    /** Permission menu ini. Bawaan: policy model (viewAny). */
    protected static function canUseMenu(): bool
    {
        return parent::canViewAny();
    }
}
