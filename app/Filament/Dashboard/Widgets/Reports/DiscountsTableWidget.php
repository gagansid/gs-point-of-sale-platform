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
 * Laporan › Diskon & void: diskon di atas batas role yang disetujui PIN (pelaku, approver, nominal).
 */
final class DiscountsTableWidget extends TableWidget
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
            ->heading('Diskon dengan approval PIN')
            ->query(fn () => app(ReportService::class)->approvedDiscountQuery($start, $end, $this->context()->filters)
                ->with(['user:id,name', 'approvedBy:id,name']))
            ->defaultSort('completed_at', 'desc')
            ->recordUrl(fn (Order $record): ?string => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record]) : null)
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Tidak ada diskon di atas batas')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('order_number')->label('No. order')->searchable()->weight('medium')
                    ->summarize(Count::make()->label('Jumlah')),
                TextColumn::make('completed_at')->label('Waktu')->dateTime('j M Y H.i')->sortable(),
                TextColumn::make('user.name')->label('Kasir'),
                TextColumn::make('approvedBy.name')->label('Disetujui'),
                TextColumn::make('discount_total')->label('Diskon')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => '−'.Money::formatWhole($state))
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::formatWhole(Money::fromDb($state)))),
                TextColumn::make('grand_total')->label('Total order')->alignEnd()->sortable()->visibleFrom('md')
                    ->formatStateUsing(fn (string $state): string => Money::formatWhole($state)),
            ]);
    }
}
