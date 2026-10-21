<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\Order;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Laporan › Diskon & void: semua order void pada periode (pelaku, approver PIN, alasan), berpaginasi.
 */
final class VoidsTableWidget extends TableWidget
{
    use ReportWidget;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    public function table(Table $table): Table
    {
        [$start, $end] = $this->context()->range();

        return $table
            ->heading('Transaksi void')
            ->query(fn () => app(ReportService::class)->voidQuery($start, $end, $this->context()->filters)
                ->with(['voidedBy:id,name', 'voidApprovedBy:id,name', 'user:id,name']))
            ->defaultSort('voided_at', 'desc')
            ->recordUrl(fn (Order $record): ?string => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record]) : null)
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Tidak ada void pada periode ini')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('order_number')->label('No. order')->searchable()->weight('medium')
                    ->summarize(Count::make()->label('Jumlah')),
                TextColumn::make('voided_at')->label('Waktu')->dateTime('j M Y H.i')->sortable(),
                TextColumn::make('grand_total')->label('Nominal')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::formatWhole($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::formatWhole(Money::fromDb($state)))),
                TextColumn::make('user.name')->label('Kasir')->visibleFrom('lg'),
                TextColumn::make('voidedBy.name')->label('Di-void oleh'),
                TextColumn::make('voidApprovedBy.name')->label('Disetujui')->placeholder('Tanpa PIN')->visibleFrom('md'),
                TextColumn::make('void_reason')->label('Alasan')->wrap()->limit(60)->visibleFrom('md'),
            ]);
    }
}
