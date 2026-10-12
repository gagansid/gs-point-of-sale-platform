<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages;

use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockReceipt;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockReceipt extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = StockReceiptResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof StockReceipt ? 'Stok masuk '.$record->number : 'Stok masuk';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Ubah draf'),
            StockReceiptActions::post(),
            StockReceiptActions::cancel(),
            StockReceiptActions::delete(),
        ];
    }
}
