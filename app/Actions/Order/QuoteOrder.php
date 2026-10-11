<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Data\CheckoutData;
use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Services\Order\CalculationResult;

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
    public function handle(Outlet $outlet, array $items, ?DiscountType $discountType, string $discountValue): CalculationResult
    {
        $lines = $this->resolveLines->handle(CheckoutData::parseItems($items), $outlet);

        return $this->priceOrder->totals($outlet, $lines, $discountType, $discountValue);
    }
}
