<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListProducts extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ProductResource::class;

    /**
     * Dideklarasikan ulang tanpa #[Url(as: 'filters')] dari ListRecords agar URL tetap rapi
     * (bukan ?filters[category_id][value]=...). Filter tetap bertahan lewat persistFiltersInSession().
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Tab cepat di atas tabel; jumlah per tab mengikuti tenant aktif (global scope).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')->badge(fn (): int => Product::query()->count()),
            'favorite' => Tab::make('Favorit')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_favorite', true))
                ->badge(fn (): int => Product::query()->where('is_favorite', true)->count()),
            'active' => Tab::make('Aktif')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true))
                ->badge(fn (): int => Product::query()->where('is_active', true)->count()),
            'sold_out' => Tab::make('Habis')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes('soldOut'))
                ->badge(fn (): int => Product::query()->soldOut()->count())
                ->badgeColor('danger'),
            'low_stock' => Tab::make('Stok menipis')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes('lowStock'))
                ->badge(fn (): int => Product::query()->lowStock()->count())
                ->badgeColor('warning'),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }
}
