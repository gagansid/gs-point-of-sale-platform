<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Pages\Dashboard;

/**
 * Beranda panel /admin: Dashboard bawaan Filament + judul berikon (HasIconBreadcrumbs).
 * Panel /dashboard memakai App\Filament\Dashboard\Pages\Home (ringkasan bisnis berfilter).
 */
final class Home extends Dashboard
{
    use HasIconBreadcrumbs;

    // Nama route tetap filament.{panel}.pages.dashboard seperti Dashboard bawaan
    protected static ?string $slug = 'dashboard';
}
