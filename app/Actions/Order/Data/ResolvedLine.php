<?php

declare(strict_types=1);

namespace App\Actions\Order\Data;

use App\Models\Option;
use App\Models\Product;

/**
 * Item order yang sudah divalidasi terhadap data server (harga & opsi dari database).
 */
final readonly class ResolvedLine
{
    /**
     * @param  list<Option>  $options
     */
    public function __construct(
        public Product $product,
        public int $qty,
        public array $options,
        public string $optionsTotal,
        public string $discount,
        public ?string $notes,
    ) {}
}
