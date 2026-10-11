<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DiscountType;

/**
 * Potongan program member untuk OrderCalculator (ADR 0013, Q63): gratis stempel (nominal, dihitung server dari
 * item termurah yang memenuhi), voucher, dan diskon member/tier (%). Bukan diskon manual: tidak dihitung ke batas
 * diskon role (Q18).
 */
final readonly class MemberPricing
{
    public function __construct(
        public string $stampFree = '0.00',
        public ?DiscountType $voucherType = null,
        public string $voucherValue = '0.00',
        // Maks. potongan voucher persen; null = tanpa batas
        public ?string $voucherMax = null,
        public string $memberPercent = '0.00',
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
