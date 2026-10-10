<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SalesLeads\Pages;

use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\SalesLead;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListSalesLeads extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = SalesLeadResource::class;

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
        $tabs = ['all' => Tab::make('Semua')->badge(fn (): int => SalesLead::query()->count())];

        foreach (SalesLeadStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status))
                ->badge(fn (): int => SalesLead::query()->where('status', $status)->count())
                ->badgeColor($status === SalesLeadStatus::New ? 'info' : null);
        }

        return $tabs;
    }
}
