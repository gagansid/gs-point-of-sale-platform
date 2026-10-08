<?php

declare(strict_types=1);

namespace App\Filament\Shared\Filters;

use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter rentang tanggal LOKAL outlet (table.md); dikonversi ke UTC untuk query.
 */
final class DateRangeFilter
{
    public static function make(string $column, string $label = 'Tanggal', bool $defaultToday = true): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label('Dari')->native(false)->displayFormat('j M Y')
                    ->default($defaultToday ? CurrentOutlet::today() : null),
                DatePicker::make('to')->label('Sampai')->native(false)->displayFormat('j M Y')
                    ->default($defaultToday ? CurrentOutlet::today() : null),
            ])
            ->query(function (Builder $query, array $data) use ($column): void {
                $from = $data['from'] ?? null;
                $to = $data['to'] ?? null;

                if (blank($from) && blank($to)) {
                    return;
                }

                [$start, $end] = CurrentOutlet::utcRange((string) ($from ?: $to), (string) ($to ?: $from));
                $query->whereBetween($query->qualifyColumn($column), [$start, $end]);
            })
            ->indicateUsing(function (array $data): array {
                $from = $data['from'] ?? null;
                $to = $data['to'] ?? null;

                if (blank($from) && blank($to)) {
                    return [];
                }

                $label = fn (?string $date): string => CarbonImmutable::parse((string) $date)->locale('id')->translatedFormat('j M Y');
                $from = $label($from ?: $to);
                $to = $label($to ?: $from);

                return [Indicator::make($from === $to ? "Tanggal {$from}" : "{$from} s/d {$to}")];
            });
    }
}
