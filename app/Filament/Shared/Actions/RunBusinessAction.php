<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Exceptions\BusinessException;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Jalankan Action bisnis dari aksi Filament; BusinessException tampil sebagai notifikasi (pesan +
 * rincian per field) dan modal tetap terbuka.
 */
final class RunBusinessAction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback, Action $action): mixed
    {
        try {
            return $callback();
        } catch (BusinessException $e) {
            $details = is_array($e->details) ? implode(' ', array_merge(...array_values(array_map(
                fn (mixed $messages): array => array_map('strval', (array) $messages),
                $e->details,
            )))) : null;

            Notification::make()->danger()->title($e->getMessage())->body($details)->send();

            // halt() melempar Halt: modal tetap terbuka
            $action->halt();

            throw $e;
        }
    }
}
