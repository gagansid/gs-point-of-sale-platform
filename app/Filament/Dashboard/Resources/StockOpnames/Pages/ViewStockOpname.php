<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockOpnames\Pages;

use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockOpname;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockOpname extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = StockOpnameResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof StockOpname ? 'Stok opname '.$record->number : 'Stok opname';
    }
}
