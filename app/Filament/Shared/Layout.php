<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Filament\View\PanelsRenderHook;

/**
 * Kerangka panel yang sama untuk /admin & /dashboard, meniru gs-task-tracker
 * (docs/standards/ui/layout.md): sidebar 252px (ciut 64px), topbar 56px dengan
 * pencarian ⌘K + menu akun, dan footer "gs.POS © tahun · versi".
 */
final class Layout
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->sidebarWidth('15.75rem')
            ->collapsedSidebarWidth('4rem')
            ->sidebarCollapsibleOnDesktop()
            // Tombol ciutkan memakai ikon ☰ seperti gs-task-tracker (bawaan Filament: chevron)
            ->icons([
                PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => Heroicon::OutlinedBars3,
                PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => Heroicon::OutlinedBars3,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('filament.shared.footer'));
    }
}
