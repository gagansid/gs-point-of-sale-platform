<?php

declare(strict_types=1);

namespace App\Filament\Shared\Columns;

use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Kolom audit "Dibuat" & "Diubah" + pelakunya (audit-info.md): tersembunyi bawaan, bisa ditampilkan lewat pilih kolom.
 * Zona waktu mengikuti panel (outlet aktif, ADR 0001).
 */
final class AuditColumns
{
    /** @return list<TextColumn> */
    public static function make(bool $created = true): array
    {
        return array_values(array_filter([
            $created ? TextColumn::make('created_at')->label('Dibuat')
                ->dateTime('j M Y, H.i')
                ->description(fn (Model $record): ?string => self::by($record, 'created_by'))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true) : null,
            // Relatif bila < 1 hari, selebihnya tanggal asli (sama seperti AuditInfo)
            TextColumn::make('updated_at')->label('Diubah')
                ->formatStateUsing(fn (CarbonInterface $state): string => $state->gt(now()->subDay())
                    ? $state->diffForHumans()
                    : $state->copy()->setTimezone(FilamentTimezone::get())->translatedFormat('j M Y, H.i'))
                ->dateTimeTooltip('j M Y, H.i')
                ->description(fn (Model $record): ?string => self::by($record, 'updated_by'))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    private static function by(Model $record, string $column): ?string
    {
        $name = method_exists($record, 'authorName') ? $record->authorName($column) : null;

        return $name !== null ? 'oleh '.$name : null;
    }
}
