<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Categories\Pages;

use App\Actions\Product\SaveCategory;
use App\Filament\Dashboard\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah kategori')
                ->icon(Heroicon::OutlinedPlus)
                ->createAnother(false)
                ->using(fn (array $data): Category => app(SaveCategory::class)->handle(null, (string) $data['name'])['category']),
        ];
    }
}
