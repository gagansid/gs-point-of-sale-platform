<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\SelfOrders\Pages;

use App\Enums\SelfOrderStatus;
use App\Filament\Dashboard\Resources\SelfOrders\SelfOrderResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListSelfOrders extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = SelfOrderResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Tab status. Tanpa angka: daftar dibatasi filter tanggal (sama dengan Penjualan).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua'),
            ...collect(SelfOrderStatus::cases())->mapWithKeys(fn (SelfOrderStatus $status): array => [
                $status->value => Tab::make($status->getLabel())
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status)),
            ])->all(),
        ];
    }
}
