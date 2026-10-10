<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Zona waktu outlet yang didukung (ADR 0001): tiga zona Indonesia.
 */
final class Timezones
{
    public const OPTIONS = [
        'Asia/Jakarta' => 'WIB — Asia/Jakarta',
        'Asia/Makassar' => 'WITA — Asia/Makassar',
        'Asia/Jayapura' => 'WIT — Asia/Jayapura',
    ];
}
