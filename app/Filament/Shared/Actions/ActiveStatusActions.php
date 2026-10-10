<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Exceptions\BusinessException;
use App\Filament\Shared\Layout;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Aksi baris "Nonaktifkan" (konfirmasi, karena data hilang dari kasir) dan "Aktifkan" (langsung).
 * Dua aksi terpisah, bukan satu aksi berkonfirmasi bersyarat (row-actions.md).
 */
final class ActiveStatusActions
{
    /**
     * Rekaman dimuat ulang lewat query model (global scope tenant berlaku lagi) sebelum diubah.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel, bool): mixed  $setActive  memanggil Action bisnis, mis. SetCategoryActive::handle
     * @return array{0: Action, 1: Action}
     */
    public static function make(string $model, Closure $setActive, string $object, string $note, string $permission): array
    {
        $apply = fn (Model $record, bool $active): mixed => $setActive($model::query()->whereKey($record->getKey())->firstOrFail(), $active);

        $canManage = fn (): bool => auth()->user()?->can($permission) ?? false;

        return [
            Action::make('deactivate')
                ->label('Nonaktifkan')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->visible(fn (Model $record): bool => (bool) $record->getAttribute('is_active') && $canManage())
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedPauseCircle)
                ->modalIconColor('warning')
                ->modalHeading("Nonaktifkan {$object}")
                ->modalDescription(fn (Model $record): HtmlString => Layout::confirmText(
                    'Nonaktifkan <strong>'.e((string) $record->getAttribute('name')).'</strong>?',
                    $note,
                ))
                ->modalSubmitActionLabel('Nonaktifkan')
                ->action(function (Model $record, Action $action) use ($apply, $object): void {
                    self::orNotify(fn () => $apply($record, false), $action);

                    Notification::make()->success()->title(ucfirst($object).' dinonaktifkan')->send();
                }),
            Action::make('activate')
                ->label('Aktifkan')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->visible(fn (Model $record): bool => ! $record->getAttribute('is_active') && $canManage())
                ->action(function (Model $record, Action $action) use ($apply, $object): void {
                    self::orNotify(fn () => $apply($record, true), $action);

                    Notification::make()->success()->title(ucfirst($object).' diaktifkan')->send();
                }),
        ];
    }

    /** Aturan bisnis yang menolak (mis. owner terakhir) tampil sebagai notifikasi, bukan halaman error. */
    private static function orNotify(Closure $callback, Action $action): void
    {
        try {
            $callback();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }
}
