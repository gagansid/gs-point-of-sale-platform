<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\Concerns\SavesPurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreatePurchaseOrder extends CreateRecord
{
    use HasIconBreadcrumbs;
    use SavesPurchaseOrder;

    protected static string $resource = PurchaseOrderResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah purchase order';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        return $this->savePurchaseOrder(null, $data);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Draf PO disimpan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
