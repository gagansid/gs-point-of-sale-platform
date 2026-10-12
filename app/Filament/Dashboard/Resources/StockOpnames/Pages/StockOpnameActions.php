<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockOpnames\Pages;

use App\Actions\Stock\DeleteStockOpname;
use App\Actions\Stock\PostStockOpname;
use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Layout;
use App\Models\StockOpname;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Aksi stok opname (ADR 0015): posting & hapus draf. Hitungan yang belum disimpan ikut disimpan sebelum posting. */
final class StockOpnameActions
{
    public static function post(): Action
    {
        return Action::make('post')->label('Posting')->icon(Heroicon::OutlinedCheckCircle)->color('primary')
            ->visible(fn (StockOpname $record): bool => $record->isDraft() && StockOpnameResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Posting stok opname')
            ->modalDescription(fn (StockOpname $record): HtmlString => Layout::confirmText(
                'Posting <strong>'.e($record->number).'</strong>?',
                'Stok produk yang dihitung disamakan dengan qty fisik (selisih terhadap stok saat ini tercatat sebagai stok opname). Produk yang tidak diisi dilewati. Setelah diposting tidak bisa diubah.',
            ))
            ->modalSubmitActionLabel('Posting')
            ->action(function (StockOpname $record, Action $action, $livewire): void {
                // Simpan isian terakhir di lembar hitung dulu
                if ($livewire instanceof CountStockOpname) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                }

                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                RunBusinessAction::run(fn () => app(PostStockOpname::class)->handle($record, $user), $action);
                Notification::make()->success()->title('Stok opname diposting')->send();
                $action->redirect(StockOpnameResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')->label('Hapus draf')->icon(Heroicon::OutlinedTrash)->color('gray')
            ->visible(fn (StockOpname $record): bool => $record->isDraft() && StockOpnameResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Hapus draf stok opname')
            ->modalSubmitActionLabel('Hapus')
            ->action(function (StockOpname $record, Action $action): void {
                RunBusinessAction::run(fn () => app(DeleteStockOpname::class)->handle($record), $action);
                Notification::make()->success()->title('Draf dihapus')->send();
                $action->redirect(StockOpnameResource::getUrl('index'));
            });
    }
}
