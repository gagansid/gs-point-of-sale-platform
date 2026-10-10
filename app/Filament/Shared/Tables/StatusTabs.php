<?php

declare(strict_types=1);

namespace App\Filament\Shared\Tables;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab cepat "Semua · Aktif · Nonaktif" untuk master data ber-kolom is_active (HasCardTabs).
 * Angka dihitung lewat query model sehingga global scope tenant berlaku.
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
}
