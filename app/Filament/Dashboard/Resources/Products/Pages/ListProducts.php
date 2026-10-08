<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah produk')->icon(Heroicon::OutlinedPlus)];
    }
}
