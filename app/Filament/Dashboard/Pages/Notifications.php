<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Semua notifikasi (Q139): riwayat lengkap lonceng topbar, bernomor, tab Belum dibaca/Semua. Dibuka dari "Lihat semua
 * notifikasi" di lonceng; gerbang dashboard.access dari DashboardPage (sama dengan lonceng).
 */
final class Notifications extends DashboardPage
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.shared.notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $title = 'Notifikasi';

    protected static ?string $slug = 'notifications';

    protected static bool $shouldRegisterNavigation = false;

    public string $inboxPanel = 'dashboard';
}
