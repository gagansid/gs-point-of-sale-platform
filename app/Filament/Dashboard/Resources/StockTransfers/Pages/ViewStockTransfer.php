<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockTransfer;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockTransfer extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = StockTransferResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof StockTransfer ? 'Transfer '.$record->number : 'Transfer';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Ubah draf'),
            StockTransferActions::send(),
            StockTransferActions::receive(),
            StockTransferActions::cancel(),
            StockTransferActions::delete(),
        ];
    }
}
