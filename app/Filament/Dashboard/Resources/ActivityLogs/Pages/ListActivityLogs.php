<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\ActivityLogs\Pages;

use App\Actions\Product\SetProductAvailability;
use App\Filament\Dashboard\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\ActivityLog;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListActivityLogs extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ActivityLogResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Semua · Dibuat · Diubah (termasuk kredensial & menu habis/tersedia) · Dihapus. Angka mengikuti data yang boleh dilihat.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn (): Builder => ActivityLogResource::visibleTo(ActivityLog::query());
        $updated = fn (Builder $query): Builder => $query->whereNotIn('event', ['created', 'deleted'])->orWhereNull('event');

        return [
            'all' => Tab::make('Semua')->badge(fn (): int => $base()->count()),
            'created' => Tab::make('Dibuat')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('event', 'created'))
                ->badge(fn (): int => $base()->where('event', 'created')->count()),
            'updated' => Tab::make('Diubah')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where($updated))
                ->badge(fn (): int => $base()->where($updated)->count()),
            'availability' => Tab::make('Menu habis/tersedia')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('event', SetProductAvailability::LOG_EVENT))
                ->badge(fn (): int => $base()->where('event', SetProductAvailability::LOG_EVENT)->count()),
            'deleted' => Tab::make('Dihapus')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('event', 'deleted'))
                ->badge(fn (): int => $base()->where('event', 'deleted')->count())
                ->badgeColor('danger'),
        ];
    }
}
