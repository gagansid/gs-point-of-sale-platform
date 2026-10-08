<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Categories\Pages;

use App\Actions\Product\SaveCategory;
use App\Filament\Dashboard\Resources\Categories\CategoryResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageCategories extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = CategoryResource::class;

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
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
