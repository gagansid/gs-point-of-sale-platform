<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Enums\PaymentCategory;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Metode bayar: jumlah & nominal per metode (pembayaran lunas pada order selesai).
 */
final class PaymentMethodsTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();
        $rows = app(ReportService::class)->paymentMethods($start, $end, $this->context()->filters);
        $total = Money::add('0', ...array_column($rows, 'amount'));

        return array_map(fn (array $r): array => [
            ...$r,
            'category_label' => PaymentCategory::tryFrom($r['category'])?->getLabel() ?? $r['category'],
            'share' => self::share($r['amount'], $total),
        ], $rows);
    }

    public function table(Table $table): Table
    {
        return $this->reportTable($table, 'Per metode bayar')
            ->defaultSort('amount', 'desc')
            ->columns([
                TextColumn::make('name')->label('Metode')->sortable()->weight('medium'),
                TextColumn::make('category_label')->label('Kategori')->badge()->color('gray')->visibleFrom('md'),
                self::countColumn('count', 'Transaksi'),
                self::moneyColumn('amount', 'Nominal'),
                self::shareColumn(),
            ]);
    }
}
