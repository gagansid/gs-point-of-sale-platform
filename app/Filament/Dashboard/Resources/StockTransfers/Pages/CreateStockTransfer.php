<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Filament\Dashboard\Resources\StockTransfers\Pages\Concerns\SavesStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateStockTransfer extends CreateRecord
{
    use HasIconBreadcrumbs;
    use SavesStockTransfer;

    protected static string $resource = StockTransferResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah transfer';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveTransfer(null, $data);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Draf transfer disimpan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
