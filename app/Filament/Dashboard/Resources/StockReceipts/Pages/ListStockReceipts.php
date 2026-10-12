<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages;

use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListStockReceipts extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = StockReceiptResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }
}
