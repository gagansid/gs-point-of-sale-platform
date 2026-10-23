<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\FulfillmentQueue\Pages;

use App\Enums\FulfillmentStatus;
use App\Filament\Dashboard\Resources\FulfillmentQueue\FulfillmentQueueResource;
use App\Filament\Dashboard\Resources\FulfillmentQueue\Widgets\FulfillmentStatsWidget;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListFulfillmentQueue extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = FulfillmentQueueResource::class;

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
        return 'Pesanan meja yang sudah lunas sampai diantar. Terlambat = belum diantar '.FulfillmentQueueResource::LATE_MINUTES.' menit setelah lunas';
    }

    /** @return array<string> KPI rapat seperti Beranda & Laporan (home.css) */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'pos-kpi-compact'];
    }

    /** @return list<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [FulfillmentStatsWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Layar operasional utama staf (ADR 0027 K8): kasir web, tab baru
            Action::make('queueScreen')->label('Buka layar antrean')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (): string => route('cashier.queue'), shouldOpenInNewTab: true)
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user instanceof User && $user->canUseWebCashier() && Features::enabled('web_cashier');
                }),
        ];
    }

    /**
     * Semua · Antre · Disiapkan · Siap · Diantar · Selesai otomatis, badge = jumlah hari ini (zona outlet).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $today = fn (): Builder => FulfillmentQueueResource::getEloquentQuery()
            ->whereBetween('completed_at', CurrentOutlet::utcRange(CurrentOutlet::today(), CurrentOutlet::today()));

        $tabs = ['all' => Tab::make('Semua')->badge(fn (): int => $today()->count())];
        foreach ([
            'queued' => [FulfillmentStatus::Queued, 'warning'],
            'preparing' => [FulfillmentStatus::Preparing, 'info'],
            'ready' => [FulfillmentStatus::Ready, 'info'],
            'served' => [FulfillmentStatus::Served, 'success'],
            'auto_completed' => [FulfillmentStatus::AutoCompleted, 'gray'],
        ] as $key => [$status, $color]) {
            $tabs[$key] = Tab::make($status === FulfillmentStatus::Ready ? 'Siap' : $status->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('fulfillment_status', $status))
                ->badge(fn (): int => $today()->where('fulfillment_status', $status)->count())
                ->badgeColor($color);
        }

        return $tabs;
    }
}
