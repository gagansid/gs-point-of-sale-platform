<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\View\ActionsIconAlias;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * Kerangka panel yang sama untuk /admin & /dashboard, meniru gs-task-tracker
 * (docs/standards/ui/layout.md): sidebar 252px (ciut 64px), topbar 56px dengan
 * pencarian ⌘K + menu akun, modal konfirmasi, dan footer "gs.POS © tahun · versi".
 */
final class Layout
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->sidebarWidth('15.75rem')
            ->collapsedSidebarWidth('4rem')
            ->sidebarCollapsibleOnDesktop()
            ->icons([
                // Tombol ciutkan memakai ikon ☰ seperti gs-task-tracker (bawaan Filament: chevron)
                PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => Heroicon::OutlinedBars3,
                PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => Heroicon::OutlinedBars3,
                // Ikon aksi baris bergaris tipis (bawaan Filament: solid)
                ActionsIconAlias::VIEW_ACTION => Heroicon::OutlinedEye,
                ActionsIconAlias::EDIT_ACTION => Heroicon::OutlinedPencil,
                ActionsIconAlias::DELETE_ACTION => Heroicon::OutlinedTrash,
                ActionsIconAlias::ACTION_GROUP => Heroicon::OutlinedEllipsisVertical,
                ActionsIconAlias::DELETE_ACTION_MODAL => Heroicon::OutlinedExclamationTriangle,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->userMenuItems([
                'logout' => fn (Action $action): Action => self::logoutAction($action),
            ])
            ->bootUsing(fn () => self::configureActions())
            // Footer hanya di layout panel; hook FOOTER juga dirender di halaman login (layout sederhana)
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => Filament::auth()->check() ? view('filament.shared.footer')->render() : '');
    }

    /**
     * Modal konfirmasi gaya gs-task-tracker: ikon di samping judul, teks rata kiri, tombol rata kanan.
     */
    public static function configureActions(): void
    {
        Action::configureUsing(fn (Action $action): Action => $action
            ->modalAlignment(Alignment::Start)
            ->modalFooterActionsAlignment(Alignment::End));

        DeleteAction::configureUsing(fn (DeleteAction $action): DeleteAction => $action
            ->modalDescription(fn (DeleteAction $action): HtmlString => self::confirmText(
                'Yakin ingin menghapus <strong>'.e($action->getRecordTitle()).'</strong>?',
                'Tindakan ini tidak bisa dibatalkan.',
            )));

        DeleteBulkAction::configureUsing(fn (DeleteBulkAction $action): DeleteBulkAction => $action
            ->modalDescription(self::confirmText('Yakin ingin menghapus data yang dipilih?', 'Tindakan ini tidak bisa dibatalkan.')));
    }

    /**
     * Teks modal konfirmasi: pertanyaan (14px) + catatan abu (13px). $question boleh berisi HTML
     * yang sudah di-escape pemanggil; $note selalu di-escape di sini.
     */
    public static function confirmText(string $question, string $note): HtmlString
    {
        return new HtmlString('<span class="gs-confirm-question">'.$question.'</span><span class="gs-confirm-text">'.e($note).'</span>');
    }

    /**
     * Keluar lewat modal konfirmasi (bawaan Filament: POST langsung tanpa konfirmasi).
     * Langkahnya sama dengan Filament\Auth\Http\Controllers\LogoutController.
     */
    private static function logoutAction(Action $action): Action
    {
        return $action
            ->url(null)
            ->postToUrl(false)
            ->requiresConfirmation()
            ->color('primary')
            ->modalIcon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->modalHeading('Keluar')
            ->modalDescription(self::confirmText(
                'Yakin ingin keluar?',
                'Anda perlu masuk lagi untuk mengakses '.Filament::getBrandName().'.',
            ))
            ->modalSubmitActionLabel('Keluar')
            ->action(function (Action $action): void {
                Filament::auth()->logout();

                session()->invalidate();
                session()->regenerateToken();

                $action->redirect(Filament::getLoginUrl() ?? '/', navigate: false);
            });
    }
}
