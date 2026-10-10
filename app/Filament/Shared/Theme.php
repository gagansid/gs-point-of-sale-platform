<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Support\Colors\Color;

/**
 * Palet warna panel (docs/standards/ui/design-tokens.md §2), dipakai panel /admin & /dashboard.
 *
 * Primary diberikan sebagai palet eksplisit: jika hanya hex, Filament menganggapnya shade 500
 * sehingga navy berubah menjadi biru muda. Di sini #2D4282 = shade 600 (tombol, link) dan
 * navy brand #1B2A55 = shade 800.
 */
final class Theme
{
    /** @return array<string, array<int, string>|string> */
    public static function colors(): array
    {
        return [
            'primary' => [
                50 => '#f2f5fb',
                100 => '#e3e9f6',
                200 => '#c8d3ec',
                300 => '#a1b3dc',
                400 => '#6f86c2',
                500 => '#4b65a8',
                600 => '#2d4282',
                700 => '#25386f',
                800 => '#1b2a55',
                900 => '#152145',
                950 => '#0d152d',
            ],
            'gray' => Color::Slate,
            'success' => Color::Green,
            'warning' => Color::Amber,
            'danger' => Color::Red,
            'info' => Color::Blue,
            // Level role (UserRole::getColor): khusus badge role, bukan warna status
            'role-owner' => Color::Violet,
            'role-manager' => Color::Blue,
            'role-supervisor' => Color::Teal,
            'role-cashier' => Color::Slate,
        ];
    }
}
