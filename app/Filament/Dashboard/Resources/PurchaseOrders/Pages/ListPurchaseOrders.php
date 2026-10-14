<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListPurchaseOrders extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $waiting = [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::Partial->value];

        return [
            'all' => Tab::make('Semua'),
            // PO yang barangnya belum datang lengkap (ADR 0021)
            'waiting' => Tab::make('Menunggu barang')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $waiting))
                ->badge(fn (): int => PurchaseOrder::query()->whereIn('status', $waiting)->count()),
        ];
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }
}
