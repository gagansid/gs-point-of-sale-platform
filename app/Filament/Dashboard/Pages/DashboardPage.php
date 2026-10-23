<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Models\User;
use Filament\Pages\Page;

/**
 * Dasar semua halaman kustom panel /dashboard (ADR 0026): canAccess() final = gerbang akses panel penuh
 * (`dashboard.access`) + canUsePage() (permission halaman). Dijaga test DashboardAccessGateTest.
 */
abstract class DashboardPage extends Page
{
    /** false = halaman juga untuk pengguna tanpa dashboard.access (Laporan untuk report.view_shift, Q50). */
    protected static bool $requiresFullDashboard = true;

    final public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || (static::$requiresFullDashboard && ! $user->hasFullDashboard())) {
            return false;
        }

        return static::canUsePage();
    }

    /** Permission halaman ini. Bawaan: semua pengguna panel yang lolos gerbang. */
    protected static function canUsePage(): bool
    {
        return true;
    }
}
