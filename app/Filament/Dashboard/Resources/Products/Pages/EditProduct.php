<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveProduct;
use App\Filament\Dashboard\Resources\Products\Actions\ProductActions;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Product;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditProduct extends EditRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = ProductResource::class;

    protected static ?string $breadcrumb = 'Ubah';

    protected function getHeaderActions(): array
    {
        return [ProductActions::delete()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Product) {
            $data['option_group_ids'] = $record->optionGroups()->pluck('option_groups.id')->all();
            $data['current_stock'] = $record->stock_qty;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Product) {
            return $record;
        }

        $user = auth()->user();

        return app(SaveProduct::class)->handle($record, ProductData::fromArray($data), $user instanceof User ? $user : null)['product'];
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Produk berhasil diperbarui';
    }
}
