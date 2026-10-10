<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListOrders extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = OrderResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Tab status. Tanpa angka: daftar dibatasi filter tanggal, angka total semua hari akan menyesatkan.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua'),
            'completed' => self::statusTab('Selesai', OrderStatus::Completed),
            'open' => self::statusTab('Open bill', OrderStatus::Open),
            'voided' => self::statusTab('Void', OrderStatus::Voided),
        ];
    }

    private static function statusTab(string $label, OrderStatus $status): Tab
    {
        return Tab::make($label)->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
    }
}
