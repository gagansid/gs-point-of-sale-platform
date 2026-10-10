<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Pages;

use App\Enums\ShiftStatus;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListShifts extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ShiftResource::class;

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
        return [
            'all' => Tab::make('Semua'),
            'open' => Tab::make('Buka')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ShiftStatus::Open)),
            'closed' => Tab::make('Ditutup')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])),
            'difference' => Tab::make('Ada selisih')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('difference')->where('difference', '!=', 0)),
        ];
    }
}
