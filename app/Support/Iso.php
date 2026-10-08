<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Format tanggal-waktu API: ISO 8601 UTC dengan "Z" (docs/standards/api/conventions.md §4).
 */
final class Iso
{
    public static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->copy()->utc()->toIso8601ZuluString();
    }
}
