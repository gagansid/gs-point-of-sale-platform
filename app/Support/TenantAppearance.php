<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Shared\Theme;
use App\Models\Tenant;
use Filament\Enums\ThemeMode;
use Illuminate\Support\HtmlString;

/**
 * Tampilan dashboard per bisnis (Q128), diatur owner di Pengaturan → Bisnis & tampilan: warna utama (preset atau
 * bebas), gaya sidebar, mode bawaan. Disimpan di tenants.preferences; diterapkan per request oleh
 * ApplyTenantAppearance (palet Filament + token CSS gs.POS). Bawaan = merek gs.POS (navy, sidebar berwarna, terang).
 */
final class TenantAppearance
{
    public const COLOR = 'appearance.color';

    public const CUSTOM_COLOR = 'appearance.custom_color';

    public const SIDEBAR = 'appearance.sidebar';

    public const THEME_MODE = 'appearance.theme_mode';

    public const LOGO = 'appearance.logo';

    public const DEFAULT_COLOR = 'navy';

    public const CUSTOM = 'custom';

    /** Preset warna utama: kunci → [label, hex shade 600]. Semua lolos kontras teks putih (WCAG AA) di tombol. */
    public const PRESETS = [
        'navy' => ['Navy gs.POS', '#2d4282'],
        'blue' => ['Biru', '#1d4ed8'],
        'teal' => ['Toska', '#0f766e'],
        'green' => ['Hijau', '#15803d'],
        'purple' => ['Ungu', '#6d28d9'],
        'brick' => ['Merah bata', '#b42318'],
        'orange' => ['Oranye', '#c2410c'],
        'graphite' => ['Grafit', '#334155'],
    ];

    public const SIDEBARS = ['colored' => 'Berwarna', 'light' => 'Terang'];

    public const THEME_MODES = ['light' => 'Terang', 'dark' => 'Gelap', 'system' => 'Ikuti perangkat'];

    /** Kunci preset tersimpan (atau "custom"); tidak dikenal = bawaan. */
    public static function colorKey(Tenant $tenant): string
    {
        $key = (string) TenantPreferences::get($tenant, self::COLOR, self::DEFAULT_COLOR);

        return $key === self::CUSTOM || array_key_exists($key, self::PRESETS) ? $key : self::DEFAULT_COLOR;
    }

    /** Hex warna utama (#rrggbb). */
    public static function hex(Tenant $tenant): string
    {
        $key = self::colorKey($tenant);
        if ($key === self::CUSTOM) {
            $custom = strtolower((string) TenantPreferences::get($tenant, self::CUSTOM_COLOR, ''));

            return self::isValidHex($custom) ? $custom : self::PRESETS[self::DEFAULT_COLOR][1];
        }

        return self::PRESETS[$key][1];
    }

    public static function sidebar(Tenant $tenant): string
    {
        $value = (string) TenantPreferences::get($tenant, self::SIDEBAR, 'colored');

        return array_key_exists($value, self::SIDEBARS) ? $value : 'colored';
    }

    public static function themeMode(Tenant $tenant): ThemeMode
    {
        return ThemeMode::tryFrom((string) TenantPreferences::get($tenant, self::THEME_MODE, 'light')) ?? ThemeMode::Light;
    }

    public static function logo(Tenant $tenant): ?string
    {
        $path = TenantPreferences::get($tenant, self::LOGO);

        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function isDefault(Tenant $tenant): bool
    {
        return self::hex($tenant) === self::PRESETS[self::DEFAULT_COLOR][1] && self::sidebar($tenant) === 'colored';
    }

    public static function isValidHex(string $hex): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/', strtolower($hex)) === 1;
    }

    /** Warna bebas wajib cukup gelap agar teks putih di tombol & sidebar terbaca (kontras ≥ 4.5:1). */
    public static function isReadableOnWhiteText(string $hex): bool
    {
        [$r, $g, $b] = self::rgb($hex);
        $luminance = fn (int $c): float => ($c / 255) <= 0.03928 ? ($c / 255) / 12.92 : ((($c / 255) + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $luminance($r) + 0.7152 * $luminance($g) + 0.0722 * $luminance($b);

        return (1.05 / ($l + 0.05)) >= 4.5;
    }

    /**
     * Palet Filament 50–950 berpatokan warna pilihan di shade 600 (tombol & tautan), campuran putih/hitam untuk shade
     * lain. Navy bawaan memakai palet merek (Theme::colors()).
     *
     * @return array<int, string>
     */
    public static function palette(string $hex): array
    {
        if ($hex === self::PRESETS[self::DEFAULT_COLOR][1]) {
            /** @var array<int, string> $brand */
            $brand = Theme::colors()['primary'];

            return $brand;
        }

        $tint = fn (float $white): string => self::mix($hex, '#ffffff', $white);
        $shade = fn (float $black): string => self::mix($hex, '#000000', $black);

        return [
            50 => $tint(0.95), 100 => $tint(0.89), 200 => $tint(0.76), 300 => $tint(0.56), 400 => $tint(0.32),
            500 => $tint(0.14), 600 => $hex, 700 => $shade(0.16), 800 => $shade(0.34), 900 => $shade(0.48), 950 => $shade(0.64),
        ];
    }

    /** <style> token gs.POS untuk bisnis ini (null = bawaan, tidak perlu override). */
    public static function css(Tenant $tenant): ?HtmlString
    {
        if (self::isDefault($tenant)) {
            return null;
        }

        $p = self::palette(self::hex($tenant));
        $light = [
            '--primary' => $p[600], '--primary-dk' => $p[800], '--primary-lt' => 'color-mix(in srgb, '.$p[600].' 8%, transparent)',
            '--primary-btn' => $p[600], '--primary-btn-hover' => $p[700],
            '--field-focus-ring' => 'color-mix(in srgb, '.$p[600].' 16%, transparent)',
            '--brand-navy' => $p[800],
        ];

        if (self::sidebar($tenant) === 'light') {
            $light += [
                '--sidebar-bg' => '#ffffff', '--sidebar-text' => '#4b5563', '--sidebar-text-hover' => '#111827',
                '--sidebar-hover' => '#f3f4f6', '--sidebar-active-bg' => 'color-mix(in srgb, '.$p[600].' 10%, transparent)',
                '--sidebar-border' => '#e6e7eb', '--sidebar-group-label' => '#9aa2b1', '--sidebar-accent' => $p[600],
            ];
        } else {
            $light += ['--sidebar-bg' => $p[600], '--sidebar-accent' => $p[200], '--sidebar-text' => $p[100]];
        }

        $dark = [
            '--primary' => $p[300], '--primary-dk' => $p[400], '--primary-lt' => 'color-mix(in srgb, '.$p[300].' 14%, transparent)',
            '--primary-btn' => $p[500], '--primary-btn-hover' => $p[400], '--sidebar-accent' => $p[300],
            '--field-focus-ring' => 'color-mix(in srgb, '.$p[300].' 22%, transparent)',
            // Mode gelap: sidebar tetap navy gelap standar (override terang di :root dimuat belakangan)
            '--sidebar-bg' => '#0f1726', '--sidebar-text' => '#aab5cc', '--sidebar-text-hover' => '#ffffff',
            '--sidebar-hover' => 'color-mix(in srgb, '.$p[300].' 8%, transparent)',
            '--sidebar-active-bg' => 'color-mix(in srgb, '.$p[300].' 14%, transparent)',
            '--sidebar-border' => 'rgb(148 163 204 / 0.1)', '--sidebar-group-label' => 'rgb(170 181 204 / 0.55)',
        ];

        $vars = fn (array $tokens): string => implode('', array_map(fn (string $k, string $v): string => $k.':'.$v.';', array_keys($tokens), $tokens));
        $css = ':root{'.$vars($light).'}.dark{'.$vars($dark).'}';
        // Sidebar terang: logo & teks merek kembali ke varian gelap
        if (self::sidebar($tenant) === 'light') {
            $css .= 'html:not(.dark) .fi-sidebar .brand-word{color:'.$p[800].'}html:not(.dark) .fi-sidebar .brand-word .w-gs{color:'.$p[600].'}'
                .'html:not(.dark) .fi-sidebar .brand-mark .bm-frame{stroke:'.$p[800].'}html:not(.dark) .fi-sidebar .brand-mark .bm-accent{stroke:#8a919e}'
                .'html:not(.dark) .fi-sidebar .brand-mark .bm-dot{fill:'.$p[600].'}html:not(.dark) .fi-sidebar{border-inline-end:1px solid #e6e7eb}'
                .'html:not(.dark) .pos-outlet-switcher__trigger{background:#f9fafb}';
        }

        // Nilai hanya hex/color-mix dari kode di atas (bukan input mentah): aman disisipkan
        return new HtmlString('<style id="gs-tenant-appearance">'.$css.'</style>');
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);
        $c = fn (int $a, int $b): int => (int) round($a + ($b - $a) * $amount);

        return sprintf('#%02x%02x%02x', $c($r1, $r2), $c($g1, $g2), $c($b1, $b2));
    }
}
