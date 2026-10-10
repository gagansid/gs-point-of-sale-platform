<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Categories\Pages;

use App\Actions\Product\SaveCategory;
use App\Filament\Dashboard\Resources\Categories\CategoryResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ManageCategories extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = CategoryResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return StatusTabs::make(Category::class);
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah')
                ->modalHeading('Tambah kategori')
                ->icon(Heroicon::OutlinedPlus)
                ->createAnother(false)
                ->using(fn (array $data): Category => app(SaveCategory::class)->handle(null, (string) $data['name'], isActive: (bool) ($data['is_active'] ?? true))['category']),
        ];
    }
}
