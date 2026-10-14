<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\Concerns\SavesPurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditPurchaseOrder extends EditRecord
{
    use HasIconBreadcrumbs;
    use SavesPurchaseOrder;

    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $breadcrumb = 'Ubah draf';

    protected function getHeaderActions(): array
    {
        return [PurchaseOrderActions::send(), PurchaseOrderActions::delete()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof PurchaseOrder) {
            $data['ordered_on'] = $record->ordered_on->toDateString();
            $data['expected_on'] = $record->expected_on?->toDateString();
            $data['items'] = $record->items()->get()->map(fn ($i): array => [
                'product_id' => $i->product_id, 'qty' => $i->qty, 'unit_cost' => $i->unit_cost,
            ])->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record instanceof PurchaseOrder ? $this->savePurchaseOrder($record, $data) : $record;
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Draf PO diperbarui';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
