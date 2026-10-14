<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis promo otomatis (ADR 0022). */
enum PromotionType: string implements HasLabel
{
    /** Diskon per unit untuk semua/produk/kategori tertentu (mis. happy hour) */
    case ItemDiscount = 'item_discount';

    /** Beli X gratis Y (unit termurah gratis) */
    case BuyGet = 'buy_get';

    /** Diskon order dengan minimal belanja */
    case OrderDiscount = 'order_discount';

    public function getLabel(): string
    {
        return match ($this) {
            self::ItemDiscount => 'Diskon produk',
            self::BuyGet => 'Beli X gratis Y',
            self::OrderDiscount => 'Diskon minimal belanja',
        };
    }
}
