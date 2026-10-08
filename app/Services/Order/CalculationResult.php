<?php

declare(strict_types=1);

namespace App\Services\Order;

/**
 * Hasil hitung order. Semua nominal string desimal 2 digit.
 */
final readonly class CalculationResult
{
    /**
     * @param  list<array{gross: string, discount: string, line_total: string}>  $lines
     */
    public function __construct(
        public array $lines,
        public string $grossTotal,
        public string $subtotal,
        public string $orderDiscount,
        public string $discountTotal,
        public string $serviceTotal,
        public string $taxTotal,
        public string $rounding,
        public string $grandTotal,
    ) {}

    /** Persen total diskon terhadap harga kotor (untuk batas diskon per role). */
    public function discountPercent(): string
    {
        if (bccomp($this->grossTotal, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul($this->discountTotal, '100', 6), $this->grossTotal, 2);
    }
}
