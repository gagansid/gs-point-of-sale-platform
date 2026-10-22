<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Receivables\Pages;

use App\Filament\Dashboard\Resources\Receivables\ReceivableResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListReceivables extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ReceivableResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    public function getSubheading(): string
    {
        return 'Kasbon member di semua outlet. Lama = belum lunas '.ReceivableResource::OVERDUE_DAYS.' hari atau lebih';
    }

    /**
     * Semua (pernah kasbon) · Belum lunas · Lama · Melebihi batas · Lunas.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn (): Builder => ReceivableResource::getEloquentQuery();
        $outstanding = fn (Builder $query): Builder => $query->where('receivable_balance', '>', 0);
        $settled = fn (Builder $query): Builder => $query->where('receivable_balance', '<=', 0);

        return [
            'all' => Tab::make('Semua')->badge(fn (): int => $base()->count()),
            'outstanding' => Tab::make('Belum lunas')
                ->modifyQueryUsing($outstanding)
                ->badge(fn (): int => $outstanding($base())->count())
                ->badgeColor('warning'),
            'overdue' => Tab::make('Lama')
                ->modifyQueryUsing(fn (Builder $query): Builder => ReceivableResource::overdue($query))
                ->badge(fn (): int => ReceivableResource::overdue($base())->count())
                ->badgeColor('danger'),
            'over_limit' => Tab::make('Melebihi batas')
                ->modifyQueryUsing(fn (Builder $query): Builder => ReceivableResource::overLimit($query))
                ->badge(fn (): int => ReceivableResource::overLimit($base())->count())
                ->badgeColor('danger'),
            'settled' => Tab::make('Lunas')
                ->modifyQueryUsing($settled)
                ->badge(fn (): int => $settled($base())->count()),
        ];
    }
}
