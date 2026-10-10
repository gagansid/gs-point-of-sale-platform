<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\View\FormsIconAlias;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\Schemas\View\SchemaIconAlias;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\SupportIconAlias;
use Filament\Tables\View\TablesIconAlias;
use Filament\View\PanelsIconAlias;

/**
 * Ikon bawaan Filament diganti versi outline (garis 1.5px) agar seragam dengan ikon sidebar & aksi baris
 * (docs/standards/ui/components/icon.md). Bawaan Filament banyak memakai Heroicons mini/solid yang terlihat tebal.
 */
final class Icons
{
    /** @return array<string, Heroicon> */
    public static function aliases(): array
    {
        return [
            // Navigasi & topbar. Tombol ciutkan memakai ☰ seperti gs-task-tracker (bawaan: chevron)
            PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => Heroicon::OutlinedBars3,
            PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => Heroicon::OutlinedBars3,
            PanelsIconAlias::TOPBAR_OPEN_SIDEBAR_BUTTON => Heroicon::OutlinedBars3,
            PanelsIconAlias::TOPBAR_CLOSE_SIDEBAR_BUTTON => Heroicon::OutlinedXMark,
            PanelsIconAlias::SIDEBAR_GROUP_COLLAPSE_BUTTON => Heroicon::OutlinedChevronUp,
            PanelsIconAlias::GLOBAL_SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
            PanelsIconAlias::USER_MENU_PROFILE_ITEM => Heroicon::OutlinedUserCircle,
            PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => Heroicon::OutlinedArrowRightStartOnRectangle,
            PanelsIconAlias::THEME_SWITCHER_LIGHT_BUTTON => Heroicon::OutlinedSun,
            PanelsIconAlias::THEME_SWITCHER_DARK_BUTTON => Heroicon::OutlinedMoon,
            PanelsIconAlias::THEME_SWITCHER_SYSTEM_BUTTON => Heroicon::OutlinedComputerDesktop,
            SupportIconAlias::BREADCRUMBS_SEPARATOR => Heroicon::OutlinedChevronRight,
            SupportIconAlias::BREADCRUMBS_SEPARATOR_RTL => Heroicon::OutlinedChevronLeft,

            // Aksi baris & modal
            ActionsIconAlias::VIEW_ACTION => Heroicon::OutlinedEye,
            ActionsIconAlias::VIEW_ACTION_GROUPED => Heroicon::OutlinedEye,
            ActionsIconAlias::EDIT_ACTION => Heroicon::OutlinedPencil,
            ActionsIconAlias::EDIT_ACTION_GROUPED => Heroicon::OutlinedPencil,
            ActionsIconAlias::DELETE_ACTION => Heroicon::OutlinedTrash,
            ActionsIconAlias::DELETE_ACTION_GROUPED => Heroicon::OutlinedTrash,
            ActionsIconAlias::ACTION_GROUP => Heroicon::OutlinedEllipsisVertical,
            ActionsIconAlias::DELETE_ACTION_MODAL => Heroicon::OutlinedExclamationTriangle,
            SupportIconAlias::MODAL_CLOSE_BUTTON => Heroicon::OutlinedXMark,
            SupportIconAlias::BADGE_DELETE_BUTTON => Heroicon::OutlinedXMark,
            SupportIconAlias::SECTION_COLLAPSE_BUTTON => Heroicon::OutlinedChevronUp,

            // Tabel: urut kolom selalu ↕, ↑/↓ saat aktif; pencarian, filter, pilih kolom, paginasi
            TablesIconAlias::HEADER_CELL_SORT_BUTTON => Heroicon::OutlinedChevronUpDown,
            TablesIconAlias::HEADER_CELL_SORT_ASC_BUTTON => Heroicon::OutlinedChevronUp,
            TablesIconAlias::HEADER_CELL_SORT_DESC_BUTTON => Heroicon::OutlinedChevronDown,
            TablesIconAlias::SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
            TablesIconAlias::ACTIONS_FILTER => Heroicon::OutlinedFunnel,
            TablesIconAlias::ACTIONS_COLUMN_MANAGER => Heroicon::OutlinedViewColumns,
            TablesIconAlias::ACTIONS_OPEN_BULK_ACTIONS => Heroicon::OutlinedEllipsisVertical,
            TablesIconAlias::ACTIONS_ENABLE_REORDERING => Heroicon::OutlinedArrowsUpDown,
            TablesIconAlias::ACTIONS_DISABLE_REORDERING => Heroicon::OutlinedCheck,
            TablesIconAlias::REORDER_HANDLE => Heroicon::OutlinedBars2,
            TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON => Heroicon::OutlinedXMark,
            TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE => Heroicon::OutlinedCheckCircle,
            TablesIconAlias::COLUMNS_ICON_COLUMN_FALSE => Heroicon::OutlinedXCircle,
            SupportIconAlias::PAGINATION_PREVIOUS_BUTTON => Heroicon::OutlinedChevronLeft,
            SupportIconAlias::PAGINATION_NEXT_BUTTON => Heroicon::OutlinedChevronRight,
            SupportIconAlias::PAGINATION_FIRST_BUTTON => Heroicon::OutlinedChevronDoubleLeft,
            SupportIconAlias::PAGINATION_LAST_BUTTON => Heroicon::OutlinedChevronDoubleRight,

            // Form
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD => Heroicon::OutlinedEye,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD => Heroicon::OutlinedEyeSlash,
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_COPY => Heroicon::OutlinedClipboard,
            FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD => Heroicon::OutlinedMagnifyingGlass,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_DELETE => Heroicon::OutlinedTrash,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_UP => Heroicon::OutlinedArrowUp,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_DOWN => Heroicon::OutlinedArrowDown,
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_REORDER => Heroicon::OutlinedArrowsUpDown,
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_CREATE_OPTION => Heroicon::OutlinedPlus,
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_EDIT_OPTION => Heroicon::OutlinedPencil,
            SchemaIconAlias::COMPONENTS_TABS_MORE_TABS_BUTTON => Heroicon::OutlinedEllipsisHorizontal,

            // Notifikasi
            NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON => Heroicon::OutlinedXMark,
            NotificationsIconAlias::NOTIFICATION_SUCCESS => Heroicon::OutlinedCheckCircle,
            NotificationsIconAlias::NOTIFICATION_DANGER => Heroicon::OutlinedXCircle,
            NotificationsIconAlias::NOTIFICATION_WARNING => Heroicon::OutlinedExclamationCircle,
            NotificationsIconAlias::NOTIFICATION_INFO => Heroicon::OutlinedInformationCircle,
        ];
    }
}
