<?php

declare(strict_types=1);

namespace App\Filament\Shared\Tables;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

/**
 * Judul card tabel berikon (table.md): kotak ikon 28px primary lembut + teks. Dipakai HasTableCardHeader dan tabel
 * di halaman custom/widget agar semua header card seragam. Teks di-escape di sini.
 */
final class TableHeading
{
    public static function make(string $text, string|BackedEnum|null $icon = null): Htmlable
    {
        $iconHtml = $icon !== null ? generate_icon_html($icon)?->toHtml() : null;

        return new HtmlString(
            '<span class="gs-ta-title">'
            .($iconHtml !== null ? '<span class="gs-ta-title-icon" aria-hidden="true">'.$iconHtml.'</span>' : '')
            .'<span class="gs-ta-title-text">'.e($text).'</span>'
            .'</span>'
        );
    }
}
