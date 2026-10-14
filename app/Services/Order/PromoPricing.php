<?php

declare(strict_types=1);

namespace App\Services\Order;

/**
 * Potongan promo otomatis untuk satu keranjang (ADR 0022): total & rincian per promo. Dihitung AutomaticPromotions,
 * dipakai OrderCalculator setelah gratis stempel.
 */
final readonly class PromoPricing
{
    /**
     * @param  list<array{promotion_id: string, name: string, amount: string}>  $applied
     */
    public function __construct(
        public string $discount = '0.00',
        public array $applied = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
