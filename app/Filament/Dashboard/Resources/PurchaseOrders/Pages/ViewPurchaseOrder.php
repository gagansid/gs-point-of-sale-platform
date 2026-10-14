<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\PurchaseOrder;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseOrder extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof PurchaseOrder ? 'Purchase order '.$record->number : 'Purchase order';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Ubah draf'),
            PurchaseOrderActions::send(),
            PurchaseOrderActions::receive(),
            PurchaseOrderActions::cancel(),
            PurchaseOrderActions::delete(),
        ];
    }
}
