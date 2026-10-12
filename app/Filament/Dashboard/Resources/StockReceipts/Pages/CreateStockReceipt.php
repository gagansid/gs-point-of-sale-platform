<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages;

use App\Filament\Dashboard\Resources\StockReceipts\Pages\Concerns\SavesStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateStockReceipt extends CreateRecord
{
    use HasIconBreadcrumbs;
    use SavesStockReceipt;

    protected static string $resource = StockReceiptResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah stok masuk';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveReceipt(null, $data);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Draf stok masuk disimpan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
