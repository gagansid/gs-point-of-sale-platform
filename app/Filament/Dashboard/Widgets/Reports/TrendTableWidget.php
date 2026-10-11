<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Ringkasan: data di balik grafik tren omzet (chart.md › Aksesibilitas): nilai yang sama,
 * terbaca screen reader & bisa diurutkan, termasuk pembanding per bucket.
 */
final class TrendTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        $reports = app(ReportService::class);
        $context = $this->context();
        [$start, $end] = $context->range();
        $timezone = CurrentOutlet::timezone();

        $current = $reports->trend($start, $end, $timezone, $context->granularity, $context->filters);
        // Disejajarkan per indeks bucket, sama dengan grafik (ReportTrendChartWidget)
        $previous = ($range = $context->comparisonRange()) !== null
            ? $reports->trend($range[0], $range[1], $timezone, $context->granularity, $context->filters)
            : null;

        return array_map(fn (array $row, int $i): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'order_count' => $row['order_count'],
            'revenue' => $row['revenue'],
            'previous' => $previous !== null ? ($previous[$i]['revenue'] ?? '0.00') : null,
            'change' => $previous !== null ? ReportService::change($row['revenue'], $previous[$i]['revenue'] ?? '0.00') : null,
        ], $current, array_keys($current));
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();
        $hasPrevious = $rows !== [] && $rows[0]['previous'] !== null;

        return $this->totalRow($this->reportTable($table, 'Data tren omzet'), [
            'order_count' => self::sumCount($rows, 'order_count'),
            'revenue' => Money::format(self::sumMoney($rows, 'revenue')),
            'previous' => $hasPrevious ? Money::format(self::sumMoney($rows, 'previous')) : '',
        ])
            ->description('Angka yang sama dengan grafik di atas')
            ->defaultSort('key')
            ->columns([
                TextColumn::make('label')->label('Periode')->weight('medium'),
                self::countColumn('order_count', 'Transaksi'),
                self::moneyColumn('revenue', 'Omzet'),
                self::moneyColumn('previous', 'Pembanding')->visible($hasPrevious)->visibleFrom('md'),
                TextColumn::make('change')->label('Perubahan')->alignEnd()->sortable()->visible($hasPrevious)
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '–' : (str_starts_with($state, '-') ? '' : '+').str_replace('.', ',', $state).'%')
                    ->placeholder('–')
                    ->color(fn (?string $state): ?string => $state === null ? null : (str_starts_with($state, '-') ? 'danger' : 'success'))
                    ->extraAttributes(['class' => 'tabular-nums']),
            ]);
    }
}
