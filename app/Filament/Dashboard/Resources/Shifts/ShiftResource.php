<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts;

use App\Filament\Dashboard\Resources\Shifts\Pages\ListShifts;
use App\Filament\Dashboard\Resources\Shifts\Pages\ViewShift;
use App\Filament\Dashboard\Resources\Shifts\Schemas\ShiftInfolist;
use App\Filament\Dashboard\Resources\Shifts\Tables\ShiftsTable;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Menu Transaksi → Shift: riwayat shift, selisih kas per kasir, tutup paksa.
 */
final class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Shift';

    protected static ?string $modelLabel = 'shift';

    protected static ?string $pluralModelLabel = 'shift';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShiftInfolist::configure($schema);
    }

    /**
     * Outlet pilihan sidebar ("Semua outlet" = semua outlet yang dipegang user), ADR 0010.
     *
     * @return Builder<Shift>
     */
    public static function getEloquentQuery(): Builder
    {
        return CurrentOutlet::scope(Shift::query());
    }

    public static function table(Table $table): Table
    {
        return ShiftsTable::configure($table);
    }

    public static function hasRecordTitle(): bool
    {
        return true;
    }

    /** Judul data di breadcrumb/judul halaman: "Budi · 10 Okt 2026, 08.00" (zona outlet). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof Shift) {
            return parent::getRecordTitle($record);
        }

        return ($record->openedBy->name ?? 'Shift').' · '
            .$record->opened_at->copy()->setTimezone(FilamentTimezone::get())->translatedFormat('j M Y, H.i');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'view' => ViewShift::route('/{record}'),
        ];
    }
}
