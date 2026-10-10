<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Pages;

use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\OptionGroup;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ListOptionGroups extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = OptionGroupResource::class;

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
        return StatusTabs::make(OptionGroup::class);
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }
}
