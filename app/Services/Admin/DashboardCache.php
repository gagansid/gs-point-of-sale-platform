<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache angka Dasbor /admin (query lintas tenant): 10 menit, dikosongkan tombol "Muat ulang data". Cache driver
 * database (shared hosting) tidak mendukung tag, jadi pengosongan memakai "generasi" di nama key.
 */
final class DashboardCache
{
    private const GENERATION = 'admin-dashboard:generation';

    private const TTL_SECONDS = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        return Cache::remember('admin-dashboard:'.self::generation().':'.$key, self::TTL_SECONDS, $callback);
    }

    /** Waktu data dihitung (generasi saat ini). */
    public static function generatedAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(self::timestamp(self::generation()));
    }

    public static function flush(): string
    {
        // Penanda acak: dua kali muat ulang di detik yang sama tetap menghasilkan generasi baru
        $generation = now()->getTimestamp().'-'.Str::random(6);
        Cache::forever(self::GENERATION, $generation);

        return $generation;
    }

    private static function generation(): string
    {
        $generation = Cache::get(self::GENERATION);

        // Generasi kedaluwarsa bersama datanya: angka tidak pernah lebih tua dari TTL
        if (! is_string($generation) || self::timestamp($generation) < now()->getTimestamp() - self::TTL_SECONDS) {
            $generation = self::flush();
        }

        return $generation;
    }

    private static function timestamp(string $generation): int
    {
        return (int) strtok($generation, '-');
    }
}
