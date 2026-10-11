<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\ResolveMemberPricing;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Outlet;
use App\Services\Order\CalculationResult;
use App\Services\Order\MemberPricing;

/**
 * Pratinjau total keranjang kasir web (Q54) tanpa menyimpan apa pun: harga dari katalog outlet dan
 * OrderCalculator, sama persis dengan checkout. Batas diskon & pembayaran diperiksa saat checkout.
 */
final class QuoteOrder
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly PriceOrder $priceOrder,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  bentuk sama dengan CheckoutData::parseItems
     *
     * @throws BusinessException produk/opsi tidak valid atau total melebihi batas
     */
    public function handle(Outlet $outlet, array $items, ?DiscountType $discountType, string $discountValue, ?Member $member = null, ?BenefitSelection $benefits = null): CalculationResult
    {
        return $this->quote($outlet, $items, $discountType, $discountValue, $member, $benefits)['totals'];
    }

    /**
     * Total + potongan program member yang dipakai (mis. rincian gratis stempel untuk ditampilkan).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{totals: CalculationResult, member: MemberPricing}
     *
     * @throws BusinessException
     */
    public function quote(Outlet $outlet, array $items, ?DiscountType $discountType, string $discountValue, ?Member $member = null, ?BenefitSelection $benefits = null): array
    {
        $lines = $this->resolveLines->handle(CheckoutData::parseItems($items), $outlet);
        $pricing = app(ResolveMemberPricing::class)->handle($member, $outlet, $lines, $benefits);

        return ['totals' => $this->priceOrder->totals($outlet, $lines, $discountType, $discountValue, $pricing), 'member' => $pricing];
    }
}
