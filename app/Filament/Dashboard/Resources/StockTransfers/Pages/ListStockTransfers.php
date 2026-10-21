<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Enums\StockTransferStatus;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\StockTransfer;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ListStockTransfers extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = StockTransferResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(StockTransfer::class, StockTransferStatus::class, [StockTransferStatus::Draft, StockTransferStatus::InTransit]);
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }
}
