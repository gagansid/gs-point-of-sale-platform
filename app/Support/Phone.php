<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nomor HP Indonesia (ADR 0013): disimpan dalam format +62… agar "0812…", "62812…", "+62 812-…" dianggap sama.
 */
final class Phone
{
    /** Normalisasi; null bila bukan nomor HP Indonesia yang wajar (08xx, 7–12 digit setelah 8). */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        $digits = match (true) {
            str_starts_with($digits, '62') => substr($digits, 2),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        return preg_match('/^8\d{7,12}$/', $digits) === 1 ? '+62'.$digits : null;
    }

    /** "+6281234567890" → "•••• 7890" (tampilan kasir). */
    public static function masked(?string $phone): string
    {
        return $phone === null ? '—' : '•••• '.substr($phone, -4);
    }

    /** "+6281234567890" → "0812-3456-7890" (tampilan dashboard). */
    public static function display(?string $phone): string
    {
        if ($phone === null) {
            return '—';
        }

        $local = '0'.substr($phone, 3);

        return implode('-', array_filter([substr($local, 0, 4), substr($local, 4, 4), substr($local, 8)]));
    }
}
