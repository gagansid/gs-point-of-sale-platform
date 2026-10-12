<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Filament\Dashboard\Resources\StockTransfers\Pages\Concerns\SavesStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockTransfer;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditStockTransfer extends EditRecord
{
    use HasIconBreadcrumbs;
    use SavesStockTransfer;

    protected static string $resource = StockTransferResource::class;

    protected static ?string $breadcrumb = 'Ubah draf';

    protected function getHeaderActions(): array
    {
        return [StockTransferActions::send(), StockTransferActions::delete()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['items'] = $record instanceof StockTransfer ? $record->items()->get()->map(fn ($i): array => [
            'product_id' => $i->product_id, 'qty' => $i->qty_sent,
        ])->all() : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record instanceof StockTransfer ? $this->saveTransfer($record, $data) : $record;
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Draf transfer diperbarui';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
