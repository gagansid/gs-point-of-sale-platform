<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Batas frekuensi export laporan per user (R3): export sinkron & berat di shared hosting.
 * Satu kunci untuk semua jalur (dashboard Excel/PDF, API PDF shift) agar tidak bisa diakali.
 */
final class ExportThrottle
{
    /**
     * Catat satu export. Mengembalikan null bila diizinkan, atau sisa detik tunggu bila terlampaui.
     */
    public static function attempt(User $user): ?int
    {
        $key = 'export:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('pos.rate_limits.export'))) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    public static function message(int $seconds): string
    {
        return "Terlalu sering export. Coba lagi dalam {$seconds} detik.";
    }
}
