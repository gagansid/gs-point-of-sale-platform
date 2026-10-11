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
 *   3. Dasar        = subtotal − gratis stempel − diskon order − voucher − diskon member/tier (ADR 0013, Q63)
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
        ?MemberPricing $member = null,
    ): CalculationResult {
        $member ??= MemberPricing::none();
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

        // Gratis stempel: nominal item gratis (server), sebelum diskon manual
        $stampDiscount = Money::min(Money::max($member->stampFree, '0.00'), $subtotal);
        $afterStamp = Money::sub($subtotal, $stampDiscount);

        $orderDiscount = self::discount($discountType, $discountValue, $afterStamp);
        $afterManual = Money::sub($afterStamp, $orderDiscount);

        $voucherDiscount = self::discount($member->voucherType, $member->voucherValue, $afterManual);
        if ($member->voucherMax !== null && $member->voucherType === DiscountType::Percent) {
            $voucherDiscount = Money::min($voucherDiscount, Money::max($member->voucherMax, '0.00'));
        }
        $afterVoucher = Money::sub($afterManual, $voucherDiscount);

        $memberDiscount = self::discount(Money::isPositive($member->memberPercent) ? DiscountType::Percent : null, $member->memberPercent, $afterVoucher);

        $base = Money::sub($afterVoucher, $memberDiscount);
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
            discountTotal: Money::add($itemDiscounts, $stampDiscount, $orderDiscount, $voucherDiscount, $memberDiscount),
            serviceTotal: $service,
            taxTotal: $tax,
            rounding: Money::sub($grandTotal, $beforeRounding),
            grandTotal: $grandTotal,
            manualDiscount: Money::add($itemDiscounts, $orderDiscount),
            stampDiscount: $stampDiscount,
            voucherDiscount: $voucherDiscount,
            memberDiscount: $memberDiscount,
        );
    }

    /** Potongan nominal/persen dari $amount, tidak minus dan tidak melebihi $amount. */
    private static function discount(?DiscountType $type, string $value, string $amount): string
    {
        $discount = match ($type) {
            null => '0.00',
            DiscountType::Fixed => Money::of($value),
            DiscountType::Percent => Money::percentOf($amount, Money::min(Money::of($value), '100.00')),
        };

        return Money::min(Money::max($discount, '0.00'), $amount);
    }
}
