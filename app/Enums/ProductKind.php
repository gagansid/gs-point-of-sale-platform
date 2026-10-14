<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Jenis produk (ADR 0020). */
enum ProductKind: string implements HasColor, HasLabel
{
    /** Dijual; boleh punya resep (stok dari bahan) atau melacak stok sendiri */
    case Regular = 'regular';

    /** Bahan baku: tidak dijual, stok selalu dilacak */
    case Ingredient = 'ingredient';

    /** Paket: dijual, stok dipotong dari isinya */
    case Bundle = 'bundle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Regular => 'Produk',
            self::Ingredient => 'Bahan baku',
            self::Bundle => 'Paket',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Regular => 'gray',
            self::Ingredient => 'warning',
            self::Bundle => 'info',
        };
    }

    public function isSellable(): bool
    {
        return $this !== self::Ingredient;
    }
}
