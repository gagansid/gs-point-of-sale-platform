<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockMovements\Pages;

use App\Enums\StockMovementType;
use App\Filament\Dashboard\Resources\StockMovements\StockMovementResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Product;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ListStockMovements extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = StockMovementResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Dibuka dari daftar Produk (`?product={id}`): filter ke produk itu tanpa batas tanggal. Diisi sebelum filter
     * session dimuat (bootedInteractsWithTable berjalan setelah mount), lalu tersimpan di session seperti biasa.
     */
    public function mount(): void
    {
        $productId = request()->query('product');

        if (is_string($productId) && Str::isUuid($productId) && Product::query()->withTrashed()->whereKey($productId)->exists()) {
            $this->tableFilters = ['product_id' => ['value' => $productId], 'created_at' => ['from' => null, 'to' => null]];
            $this->activeTab = 'all';
            session()->put($this->getActiveTabSessionKey(), 'all');
        }

        parent::mount();
    }

    /**
     * Tanpa angka: daftar dibatasi filter tanggal, angka total semua hari akan menyesatkan (table.md).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua'),
            'in' => Tab::make('Masuk')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('qty_change', '>', 0)),
            'out' => Tab::make('Keluar')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('qty_change', '<', 0)),
            'corrections' => Tab::make('Penyesuaian & opname')->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('type', [StockMovementType::Adjustment, StockMovementType::Opname])),
        ];
    }
}
