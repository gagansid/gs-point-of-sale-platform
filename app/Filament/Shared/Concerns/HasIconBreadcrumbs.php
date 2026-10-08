<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use BackedEnum;
use Filament\Support\Enums\IconSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

/**
 * Breadcrumb sebagai judul halaman seperti gs-task-tracker (layout.md §4):
 * item pertama diberi ikon menu (mis. "🏢 Tenant › Daftar"). Halaman tanpa breadcrumb
 * (beranda, halaman custom) mendapat satu item: ikon + judul halaman.
 *
 * Label di-escape di sini; hanya ikon SVG dari Filament yang dirender sebagai HTML.
 */
trait HasIconBreadcrumbs
{
    /**
     * @return array<int|string, string|Htmlable>
     */
    public function getBreadcrumbs(): array
    {
        // ManageRecords mengembalikan satu item berlabel kosong; buang agar diganti judul halaman
        $breadcrumbs = array_filter(
            parent::getBreadcrumbs(),
            fn (mixed $label): bool => $label instanceof Htmlable || filled($label),
        );

        if ($breadcrumbs === []) {
            $breadcrumbs = [$this->getTitle()];
        }

        $firstKey = array_key_first($breadcrumbs);
        $icon = $this->getBreadcrumbIcon();

        if ($icon !== null) {
            $label = $breadcrumbs[$firstKey];
            $labelHtml = $label instanceof Htmlable ? $label->toHtml() : e($label);

            $breadcrumbs[$firstKey] = new HtmlString(
                generate_icon_html($icon, size: IconSize::Large)?->toHtml().'<span>'.$labelHtml.'</span>',
            );
        }

        return $breadcrumbs;
    }

    protected function getBreadcrumbIcon(): string|BackedEnum|Htmlable|null
    {
        if (method_exists(static::class, 'getResource')) {
            return static::getResource()::getNavigationIcon();
        }

        return static::getNavigationIcon();
    }
}
