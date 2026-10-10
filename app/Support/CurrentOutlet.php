<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Outlet;
use Carbon\CarbonImmutable;

/**
 * Outlet aktif tenant (MVP: satu outlet per bisnis — outlet pertama). Dipakai untuk zona waktu
 * laporan & filter tanggal. Saat multi-outlet (fase 2), ganti dengan outlet pilihan user.
 */
final class CurrentOutlet
{
    public static function get(): ?Outlet
    {
        return Outlet::query()->orderBy('created_at')->first();
    }

    /** Untuk endpoint/menu yang butuh outlet: tenant tanpa outlet = data rusak → 404. */
    public static function getOrFail(): Outlet
    {
        return self::get() ?? abort(404);
    }

    public static function timezone(): string
    {
        return self::get()->timezone ?? (string) config('pos.default_timezone');
    }

    /**
     * Rentang tanggal lokal outlet (inklusif) → rentang UTC untuk query.
     * Menerima "2026-10-08" maupun "2026-10-08 00:00:00" (state DatePicker Filament).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function utcRange(string $fromDate, string $toDate): array
    {
        $timezone = self::timezone();

        return [
            self::localDay($fromDate, $timezone)->startOfDay()->utc(),
            self::localDay($toDate, $timezone)->endOfDay()->utc(),
        ];
    }

    private static function localDay(string $date, string $timezone): CarbonImmutable
    {
        // Hanya bagian tanggal yang dipakai; jam (bila ada) diabaikan
        return CarbonImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10), $timezone)
            ?: throw new \InvalidArgumentException("Tanggal tidak valid: {$date}");
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }
}
