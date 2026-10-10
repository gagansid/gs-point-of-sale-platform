<?php

declare(strict_types=1);

namespace App\Filament\Shared\Columns;

use Filament\Tables\Columns\TextColumn;

/**
 * Kolom audit "Dibuat" & "Diubah" (audit-info.md): tersembunyi bawaan, bisa ditampilkan lewat pilih kolom.
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
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true) : null,
            TextColumn::make('updated_at')->label('Diubah')
                ->since()
                ->dateTimeTooltip('j M Y, H.i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }
}
