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
    /**
     * @param  list<array{stamp_program_id: string, name: string, product_id: string, product_name: string, amount: string}>  $stampRedemptions
     */
    public function __construct(
        public string $stampFree = '0.00',
        public ?DiscountType $voucherType = null,
        public string $voucherValue = '0.00',
        // Maks. potongan voucher persen; null = tanpa batas
        public ?string $voucherMax = null,
        // Diskon member dasar + diskon tier (M5), maks. 100
        public string $memberPercent = '0.00',
        // Snapshot nama tier untuk order (orders.member_tier); tidak dipakai OrderCalculator
        public ?string $tierName = null,
        // Rincian gratis stempel (orders.stamp_redemptions, M6); jumlahnya = $stampFree
        public array $stampRedemptions = [],
        // Snapshot voucher untuk order (orders.voucher_id & voucher_code, M7)
        public ?string $voucherId = null,
        public ?string $voucherLabel = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
