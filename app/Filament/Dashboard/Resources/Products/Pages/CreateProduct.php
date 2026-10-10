<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveProduct;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\User;
use App\Support\CurrentOutlet;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateProduct extends CreateRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah produk';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        return app(SaveProduct::class)->handle(null, ProductData::fromArray($data), CurrentOutlet::getOrFail(), $user instanceof User ? $user : null)['product'];
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Produk berhasil ditambahkan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
