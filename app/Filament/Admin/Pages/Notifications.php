<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Semua notifikasi super admin (Q139): riwayat lengkap lonceng topbar, bernomor, tab Belum dibaca/Semua.
 */
final class Notifications extends Page
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.shared.notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $title = 'Notifikasi';

    protected static ?string $slug = 'notifications';

    protected static bool $shouldRegisterNavigation = false;

    public string $inboxPanel = 'admin';
}
