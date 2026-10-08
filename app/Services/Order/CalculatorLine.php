<?php

declare(strict_types=1);

namespace App\Services\Order;

/**
 * Satu baris masukan kalkulator. Harga dari data server (bukan client).
 */
final readonly class CalculatorLine
{
    public function __construct(
        public string $unitPrice,
        public string $optionsTotal,
        public int $qty,
        public string $discount = '0.00',
    ) {}
}
