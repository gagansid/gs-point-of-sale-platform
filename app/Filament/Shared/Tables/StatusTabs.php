<?php

declare(strict_types=1);

namespace App\Filament\Shared\Tables;

use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab cepat di header card (HasCardTabs): "Semua · Aktif · Nonaktif" untuk master data ber-kolom is_active, dan
 * "Semua · per status" untuk dokumen (stok masuk, opname, transfer, PO). Angka dihitung lewat query model sehingga
 * global scope tenant/outlet berlaku.
 */
final class StatusTabs
{
    /**
     * @param  class-string<Model>  $model
     * @return array<string, Tab>
     */
    public static function make(string $model): array
    {
        return [
            'all' => Tab::make('Semua')->badge(fn (): int => $model::query()->count()),
            'active' => Tab::make('Aktif')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true))
                ->badge(fn (): int => $model::query()->where('is_active', true)->count()),
            'inactive' => Tab::make('Nonaktif')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false))
                ->badge(fn (): int => $model::query()->where('is_active', false)->count()),
        ];
    }

    /**
     * Semua + satu tab per status enum (label & warna dari enum). $warn = status yang angkanya diberi warna kuning
     * (perlu tindakan, mis. draf / dikirim belum diterima).
     *
     * @param  class-string<Model>  $model
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     * @param  list<\BackedEnum>  $warn
     * @return array<string, Tab>
     */
    public static function forEnum(string $model, string $enum, array $warn = [], string $column = 'status'): array
    {
        $tabs = ['all' => Tab::make('Semua')->badge(fn (): int => $model::query()->count())];

        foreach ($enum::cases() as $case) {
            $tabs[(string) $case->value] = Tab::make((string) $case->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where($column, $case->value))
                ->badge(fn (): int => $model::query()->where($column, $case->value)->count())
                ->badgeColor(in_array($case, $warn, true) ? 'warning' : null);
        }

        return $tabs;
    }
}
