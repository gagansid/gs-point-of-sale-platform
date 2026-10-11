<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Member: sebaran member per tier saat ini (ADR 0013, M5). */
final class MemberTiersTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->members($start, $end, $this->context()->filters)['tiers'];
    }

    public function table(Table $table): Table
    {
        return $this->totalRow($this->reportTable($table, 'Member per tier'), [
            'member_count' => self::sumCount($this->cachedRows(), 'member_count'),
        ])
            ->description('Saat ini, semua outlet')
            ->defaultSort('member_count', 'desc')
            ->columns([
                TextColumn::make('tier')->label('Tier')->sortable()->weight('medium'),
                self::countColumn('member_count', 'Member'),
            ]);
    }
}
