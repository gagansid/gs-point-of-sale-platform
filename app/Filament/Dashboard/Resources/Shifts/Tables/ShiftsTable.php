<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Tables;

use App\Filament\Dashboard\Resources\Shifts\Actions\ForceCloseShiftAction;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat shift: selisih kas minus merah, plus hijau (money-input.md → MoneyColumn::signed).
 */
final class ShiftsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['openedBy', 'device']))
            ->columns([
                TextColumn::make('opened_at')->label('Dibuka')->dateTime('j M Y, H.i')->sortable(),
                // Tampil saat "Semua outlet" dipilih di pemilih outlet sidebar dan bisnis punya lebih dari satu outlet (ADR 0010)
                TextColumn::make('outlet.name')->label('Outlet')->badge()->color('gray')->toggleable()
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && Outlet::query()->count() > 1),
                TextColumn::make('openedBy.name')->label('Kasir')->searchable()->sortable(),
                TextColumn::make('device.name')->label('Perangkat')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                MoneyColumn::make('opening_cash')->label('Kas awal')->sortable()->toggleable(isToggledHiddenByDefault: true),
                MoneyColumn::make('expected_cash')->label('Seharusnya')->placeholder('—')->sortable(),
                MoneyColumn::make('actual_cash')->label('Aktual')->placeholder('—')->sortable(),
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
                    ->sortable()
                    ->extraAttributes(['class' => 'is-money']),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('j M Y, H.i')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                // Audit: tersembunyi bawaan, tampilkan lewat pilih kolom
                ...AuditColumns::make(),
            ])
            ->filters([
                DateRangeFilter::make('opened_at', 'Tanggal dibuka', defaultToday: false),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Lihat'),
                ActionGroup::make([ForceCloseShiftAction::make()])->tooltip('Aksi lain'),
            ])
            ->recordUrl(fn (Shift $record): string => ShiftResource::getUrl('view', ['record' => $record]))
            ->defaultSort('opened_at', 'desc');

        return TableEmptyState::apply($table, Heroicon::OutlinedClock, 'shift', 'Shift dibuka kasir dari aplikasi');
    }
}
