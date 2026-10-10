<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Tables;

use App\Enums\ShiftStatus;
use App\Filament\Dashboard\Resources\Shifts\Actions\ForceCloseShiftAction;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Models\Shift;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat shift: selisih kas minus merah, plus hijau (money-input.md → MoneyColumn::signed).
 */
final class ShiftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['openedBy', 'device']))
            ->columns([
                TextColumn::make('opened_at')->label('Dibuka')->dateTime('j M Y, H.i')->sortable(),
                TextColumn::make('openedBy.name')->label('Kasir'),
                TextColumn::make('device.name')->label('Perangkat')->toggleable(),
                TextColumn::make('status')->label('Status')->badge(),
                MoneyColumn::make('opening_cash')->label('Kas awal')->toggleable(),
                MoneyColumn::make('expected_cash')->label('Seharusnya')->placeholder('—'),
                MoneyColumn::make('actual_cash')->label('Aktual')->placeholder('—'),
                TextColumn::make('difference')
                    ->label('Selisih')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Money::format($state))
                    ->color(fn (Shift $record): ?string => match (true) {
                        $record->difference === null => null,
                        Money::compare($record->difference, '0') < 0 => 'danger',
                        Money::compare($record->difference, '0') > 0 => 'success',
                        default => null,
                    })
                    ->alignEnd()
                    ->extraAttributes(['class' => 'is-money']),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('j M Y, H.i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                DateRangeFilter::make('opened_at', 'Tanggal dibuka', defaultToday: false),
                SelectFilter::make('status')->label('Status')->options(ShiftStatus::class),
                Filter::make('has_difference')->label('Ada selisih kas')
                    ->query(fn (Builder $query) => $query->whereNotNull('difference')->where('difference', '!=', 0)),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Lihat'),
                ForceCloseShiftAction::make()->iconButton()->tooltip('Tutup paksa'),
            ])
            ->recordUrl(fn (Shift $record): string => ShiftResource::getUrl('view', ['record' => $record]))
            ->defaultSort('opened_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->emptyStateHeading('Belum ada shift')
            ->emptyStateDescription('Shift dibuka kasir dari aplikasi');
    }
}
