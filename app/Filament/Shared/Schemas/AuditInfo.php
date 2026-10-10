<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris audit di bawah form/detail (docs/standards/ui/components/audit-info.md):
 * "Dibuat {tanggal} · Diubah {relatif}", waktu lengkap di tooltip, zona waktu panel (outlet).
 * Tidak tampil saat menambah data (belum ada record).
 */
final class AuditInfo
{
    public static function make(): View
    {
        return View::make('filament.shared.audit-info')
            ->key('auditInfo')
            ->columnSpanFull()
            ->visible(fn (?Model $record): bool => self::hasRecord($record));
    }

    /** Versi kartu "Riwayat" untuk kolom samping halaman penuh (form/detail dua kolom). */
    public static function card(): Section
    {
        return Section::make('Riwayat')
            ->key('auditCard')
            ->compact()
            ->schema([
                View::make('filament.shared.audit-info')->viewData(['stacked' => true]),
            ])
            ->visible(fn (?Model $record): bool => self::hasRecord($record));
    }

    private static function hasRecord(?Model $record): bool
    {
        return $record?->exists === true && $record->getAttribute('created_at') !== null;
    }
}
