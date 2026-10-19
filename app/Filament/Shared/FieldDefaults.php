<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\TernaryFilter;

/**
 * Bawaan field yang seragam untuk panel /admin (docs/standards/ui/components/form.md): select & tanggal memakai
 * komponen JS Filament (ikon chevron/kalender sama di semua browser, bukan kontrol bawaan OS), format tanggal
 * Indonesia, dan zona waktu platform (pos.default_timezone) agar tanggal yang diketik admin = tanggal lokal.
 *
 * Dipanggil sekali saat panel boot; field tetap bisa menimpa nilai ini per komponen.
 */
final class FieldDefaults
{
    public static function apply(): void
    {
        FilamentTimezone::set((string) config('pos.default_timezone'));

        Select::configureUsing(fn (Select $select): Select => $select->native(false));

        // Berlaku juga untuk DatePicker (turunan DateTimePicker)
        // Ikon kalender di kanan = tombol: klik membuka popup tanggal (sama dengan filter periode Laporan)
        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker
            ->native(false)
            ->seconds(false)
            ->displayFormat('j M Y, H.i')
            ->suffixAction(self::openCalendarAction($picker->getName())));

        // Filter ya/tidak: placeholder sama dengan SelectFilter ("Semua"), bukan "-"
        TernaryFilter::configureUsing(fn (TernaryFilter $filter): TernaryFilter => $filter->placeholder('Semua'));

        DatePicker::configureUsing(fn (DatePicker $picker): DatePicker => $picker
            ->displayFormat('j M Y')
            ->closeOnDateSelection());
    }

    /** Tombol ikon kalender: memicu tombol pemicu popup DateTimePicker di field yang sama (JS saja, tanpa request). */
    private static function openCalendarAction(string $field): Action
    {
        return Action::make('open_'.preg_replace('/[^A-Za-z0-9_]/', '_', $field).'_calendar')
            ->label('Buka kalender')
            ->iconButton()
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->alpineClickHandler(<<<'JS'
                $el.closest('.fi-input-wrp').querySelector('[x-ref=button]')?.click()
            JS);
    }
}
