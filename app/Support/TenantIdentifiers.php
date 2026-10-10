<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Slug tenant & kode outlet otomatis untuk bisnis baru (daftar mandiri & dari lead sales).
 */
final class TenantIdentifiers
{
    /** "Kopi Senja" → "kopi-senja", atau "kopi-senja-x7k2" bila sudah dipakai. */
    public static function uniqueSlug(string $businessName): string
    {
        $base = Str::limit(Str::slug($businessName) ?: 'bisnis', 80, '');
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /** "Kopi Senja" → "KOP01" (awalan nomor order; unik per tenant, bukan global). */
    public static function outletCode(string $businessName): string
    {
        $letters = Str::upper((string) preg_replace('/[^A-Za-z]/', '', Str::ascii($businessName)));

        return str_pad(substr($letters, 0, 3), 3, 'G').'01';
    }
}
