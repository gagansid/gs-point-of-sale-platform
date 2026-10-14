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
     * @param  list<array{promotion_id: string, name: string, amount: string}>  $promotions
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
        // Diskon manual (item + order) — dasar batas diskon role (Q18)
        public string $manualDiscount = '0.00',
        // Potongan program member (ADR 0013): tidak dihitung ke batas diskon role
        public string $stampDiscount = '0.00',
        public string $voucherDiscount = '0.00',
        public string $memberDiscount = '0.00',
        // Promo otomatis (ADR 0022): total & rincian per promo
        public string $promoDiscount = '0.00',
        public array $promotions = [],
    ) {}

    /** Persen diskon MANUAL (item + order) terhadap harga kotor, untuk batas diskon per role. */
    public function discountPercent(): string
    {
        if (bccomp($this->grossTotal, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul($this->manualDiscount, '100', 6), $this->grossTotal, 2);
    }
}
