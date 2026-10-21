<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Enums\ShiftStatus;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan › Diskon & void: shift yang DITUTUP pada periode dengan selisih kas ≠ 0 atau ditutup paksa.
 */
final class ShiftDifferencesTableWidget extends TableWidget
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
        $userIds = $this->context()->filters->userIds;

        return $table
            ->heading('Selisih kas & tutup paksa')
            ->query(fn () => CurrentOutlet::scope(Shift::query())
                ->with(['openedBy:id,name', 'closedBy:id,name'])
                ->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])
                ->whereBetween('closed_at', [$start, $end])
                ->where(fn (Builder $q) => $q->where('difference', '!=', 0)->orWhere('status', ShiftStatus::ForceClosed))
                ->when($userIds !== [], fn (Builder $q) => $q->whereIn('opened_by', $userIds)))
            ->defaultSort('closed_at', 'desc')
            ->recordUrl(fn (Shift $record): ?string => ShiftResource::canViewAny() ? ShiftResource::getUrl('view', ['record' => $record]) : null)
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Semua kas shift sesuai')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('openedBy.name')->label('Kasir')->weight('medium'),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('j M Y H.i')->sortable(),
                TextColumn::make('expected_cash')->label('Seharusnya')->alignEnd()->visibleFrom('md')
                    ->formatStateUsing(fn (?string $state): string => Money::formatWhole(Money::fromDb($state))),
                TextColumn::make('actual_cash')->label('Aktual')->alignEnd()->visibleFrom('md')
                    ->formatStateUsing(fn (?string $state): string => Money::formatWhole(Money::fromDb($state))),
                TextColumn::make('difference')->label('Selisih')->alignEnd()->sortable()->badge()
                    ->formatStateUsing(fn (?string $state): string => Money::formatWhole(Money::fromDb($state)))
                    ->color(fn (?string $state): string => Money::compare(Money::fromDb($state), '0') === 0 ? 'success' : 'danger')
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::formatWhole(Money::fromDb($state)))),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (ShiftStatus $state): string => $state === ShiftStatus::ForceClosed ? 'danger' : 'gray'),
                TextColumn::make('closedBy.name')->label('Ditutup oleh')->visibleFrom('lg'),
                TextColumn::make('close_note')->label('Catatan')->limit(50)->visibleFrom('xl'),
            ]);
    }
}
