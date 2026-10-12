<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages;

use App\Filament\Dashboard\Resources\StockReceipts\Pages\Concerns\SavesStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockReceipt;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditStockReceipt extends EditRecord
{
    use HasIconBreadcrumbs;
    use SavesStockReceipt;

    protected static string $resource = StockReceiptResource::class;

    protected static ?string $breadcrumb = 'Ubah draf';

    protected function getHeaderActions(): array
    {
        return [StockReceiptActions::post(), StockReceiptActions::delete()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['received_on'] = $record instanceof StockReceipt ? $record->received_on->toDateString() : null;
        $data['items'] = $record instanceof StockReceipt ? $record->items()->get()->map(fn ($i): array => [
            'product_id' => $i->product_id, 'qty' => $i->qty, 'unit_cost' => $i->unit_cost,
        ])->all() : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record instanceof StockReceipt ? $this->saveReceipt($record, $data) : $record;
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Draf stok masuk diperbarui';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
