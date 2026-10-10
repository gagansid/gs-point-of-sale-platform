<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Filament\Shared\Layout;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Hapus massal seragam (row-actions.md): konfirmasi wajib, cek permission, dan setiap rekaman
 * dihapus lewat Action bisnis. Rekaman terpilih dimuat ulang lewat query model sehingga global
 * scope tenant berlaku lagi — ID tenant lain yang disisipkan tidak pernah tersentuh.
 */
final class BulkDeleteAction
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel): void  $delete  memanggil Action bisnis, mis. DeleteProduct::handle
     */
    public static function make(string $model, Closure $delete, string $object, string $note, string $permission): BulkAction
    {
        return BulkAction::make('bulkDelete')
            ->label('Hapus')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->can($permission) ?? false)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading("Hapus {$object}")
            ->modalDescription(fn (Collection $records): HtmlString => Layout::confirmText(
                "Yakin ingin menghapus <strong>{$records->count()} {$object}</strong> terpilih?",
                $note,
            ))
            ->modalSubmitActionLabel('Hapus')
            ->action(function (Collection $records) use ($model, $delete, $object): void {
                $selected = $model::query()->whereKey($records->modelKeys())->get();
                $selected->each(fn (Model $record) => $delete($record));

                Notification::make()->success()->title("{$selected->count()} {$object} dihapus")->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
