<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DiscountType;
use App\Support\Money;

/**
 * SATU-SATUNYA tempat perhitungan total order (CLAUDE.md). Murni: tanpa DB, deterministik.
 * Flutter memakai rumus yang sama hanya untuk pratinjau.
 *
 * Urutan (docs/SPEC.md → Aturan bisnis → Urutan perhitungan total):
 *   1. Harga item   = (harga produk + total opsi) × qty − diskon item
 *   2. Subtotal     = Σ harga item
 *   3. Dasar        = subtotal − diskon order
 *   4. Service      = dasar × tarif service
 *   5. Pajak        = (dasar + service) × tarif pajak   (inclusive: diekstrak dari dasar + service)
 *   6. Pembulatan   = ke kelipatan Rp {rounding} terdekat (half-up)
 *   7. Grand total  = dasar + service + pajak (exclusive) + pembulatan
 *
 * Setiap hasil antara dibulatkan half-up ke 2 desimal.
 */
final class OrderCalculator
{
    /**
     * @param  list<CalculatorLine>  $lines
     */
    public function calculate(
        array $lines,
        ?DiscountType $discountType,
        string $discountValue,
        string $serviceRate,
        string $taxRate,
        bool $taxInclusive,
        int $rounding,
    ): CalculationResult {
        $resultLines = [];
        $gross = '0.00';
        $itemDiscounts = '0.00';
        $subtotal = '0.00';

        foreach ($lines as $line) {
            $lineGross = Money::mul(Money::add($line->unitPrice, $line->optionsTotal), $line->qty);
            // Diskon item tidak boleh membuat harga item minus
            $lineDiscount = Money::min(Money::max($line->discount, '0.00'), $lineGross);
            $lineTotal = Money::sub($lineGross, $lineDiscount);

            $resultLines[] = ['gross' => $lineGross, 'discount' => $lineDiscount, 'line_total' => $lineTotal];
            $gross = Money::add($gross, $lineGross);
            $itemDiscounts = Money::add($itemDiscounts, $lineDiscount);
            $subtotal = Money::add($subtotal, $lineTotal);
        }

        $orderDiscount = match ($discountType) {
            null => '0.00',
            DiscountType::Fixed => Money::of($discountValue),
            DiscountType::Percent => Money::percentOf($subtotal, Money::min(Money::of($discountValue), '100.00')),
        };
        $orderDiscount = Money::min(Money::max($orderDiscount, '0.00'), $subtotal);

        $base = Money::sub($subtotal, $orderDiscount);
        $service = Money::percentOf($base, $serviceRate);
        $beforeTax = Money::add($base, $service);

        if ($taxInclusive) {
            $tax = Money::includedTax($beforeTax, $taxRate);
            $beforeRounding = $beforeTax;
        } else {
            $tax = Money::percentOf($beforeTax, $taxRate);
            $beforeRounding = Money::add($beforeTax, $tax);
        }

        $grandTotal = Money::roundToNearest($beforeRounding, $rounding);

        return new CalculationResult(
            lines: $resultLines,
            grossTotal: $gross,
            subtotal: $subtotal,
            orderDiscount: $orderDiscount,
            discountTotal: Money::add($itemDiscounts, $orderDiscount),
            serviceTotal: $service,
            taxTotal: $tax,
            rounding: Money::sub($grandTotal, $beforeRounding),
            grandTotal: $grandTotal,
        );
    }
}
