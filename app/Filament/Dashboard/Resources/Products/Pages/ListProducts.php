<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListProducts extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ProductResource::class;

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah produk')->icon(Heroicon::OutlinedPlus)];
    }
}
