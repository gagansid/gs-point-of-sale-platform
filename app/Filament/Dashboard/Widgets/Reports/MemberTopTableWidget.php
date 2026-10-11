<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Member: 20 member dengan belanja terbesar di periode (ADR 0013, M7). */
final class MemberTopTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->members($start, $end, $this->context()->filters)['top_members'];
    }

    protected function searchColumns(): array
    {
        return ['member_name', 'tier'];
    }

    public function table(Table $table): Table
    {
        return $this->reportTable($table, 'Member teratas')
            ->description('20 member dengan belanja terbesar pada periode ini')
            ->defaultSort('revenue', 'desc')
            ->columns([
                TextColumn::make('member_name')->label('Member')->searchable()->sortable()->weight('medium'),
                TextColumn::make('tier')->label('Tier')->badge()->color('primary')->placeholder('—'),
                self::countColumn('order_count', 'Transaksi'),
                self::moneyColumn('revenue', 'Belanja'),
                self::countColumn('points_earned', 'Poin didapat')->visibleFrom('md'),
            ]);
    }
}
