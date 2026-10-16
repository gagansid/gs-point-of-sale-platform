<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Actions\Order\Data\ResolvedLine;
use App\Enums\DiscountType;
use App\Enums\PromotionType;
use App\Models\Outlet;
use App\Models\Promotion;
use App\Support\Features;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Promo otomatis yang berlaku untuk keranjang (ADR 0022, Q102–Q104), waktu lokal outlet:
 * - diskon produk: per baris satu diskon item terbesar (persen/nominal per unit, maks. harga baris);
 * - beli X gratis Y: unit baris tanpa diskon item yang memenuhi syarat diurutkan dari termahal; tiap X+Y unit, Y
 *   termurah gratis; beberapa promo beli-gratis tidak memakai unit yang sama;
 * - diskon minimal belanja: satu terbesar, dari subtotal setelah promo item.
 * Total dibatasi subtotal. Hanya membaca data; perhitungan total tetap di OrderCalculator.
 */
final class AutomaticPromotions
{
    /** @param list<ResolvedLine> $lines */
    public function for(Outlet $outlet, array $lines, ?CarbonImmutable $at = null): PromoPricing
    {
        // Paket tanpa fitur promo (ADR 0024)
        if ($lines === [] || ! Features::enabled('promotions')) {
            return PromoPricing::none();
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($outlet->timezone);
        $promotions = Promotion::query()->where('is_active', true)->get()
            ->filter(fn (Promotion $promo): bool => $promo->isRunning($outlet->id, $now))
            ->values();

        if ($promotions->isEmpty()) {
            return PromoPricing::none();
        }

        $applied = [];
        $add = function (Promotion $promo, string $amount) use (&$applied): void {
            if (! Money::isPositive($amount)) {
                return;
            }
            $applied[$promo->id] ??= ['promotion_id' => (string) $promo->id, 'name' => $promo->name, 'amount' => '0.00'];
            $applied[$promo->id]['amount'] = Money::add($applied[$promo->id]['amount'], $amount);
        };

        // Harga per unit & sisa nilai baris (setelah diskon item manual)
        $subtotal = '0.00';
        $discounted = [];
        foreach ($lines as $i => $line) {
            $unit = Money::add($line->product->price, $line->optionsTotal);
            $lineTotal = Money::max(Money::sub(Money::mul($unit, $line->qty), $line->discount), '0.00');
            $subtotal = Money::add($subtotal, $lineTotal);

            $best = null;
            $bestAmount = '0.00';
            foreach ($promotions->where('type', PromotionType::ItemDiscount) as $promo) {
                if (! $promo->covers($line->product->id, $line->product->category_id)) {
                    continue;
                }
                $perUnit = $promo->discount_type === DiscountType::Percent
                    ? Money::percentOf($unit, Money::min($promo->discount_value, '100.00'))
                    : Money::min($promo->discount_value, $unit);
                $amount = Money::min(Money::mul($perUnit, $line->qty), $lineTotal);
                if (Money::compare($amount, $bestAmount) > 0) {
                    [$best, $bestAmount] = [$promo, $amount];
                }
            }

            if ($best !== null) {
                $add($best, $bestAmount);
                $discounted[$i] = true;
            }
        }

        // Beli X gratis Y: unit yang belum kena diskon item
        $used = [];
        foreach ($promotions->where('type', PromotionType::BuyGet) as $promo) {
            $buy = max(1, (int) $promo->buy_qty);
            $get = max(1, (int) $promo->get_qty);
            $units = [];
            foreach ($lines as $i => $line) {
                if (isset($discounted[$i]) || ! $promo->covers($line->product->id, $line->product->category_id)) {
                    continue;
                }
                $available = $line->qty - ($used[$i] ?? 0);
                $unit = Money::add($line->product->price, $line->optionsTotal);
                for ($n = 0; $n < $available; $n++) {
                    $units[] = ['line' => $i, 'price' => $unit];
                }
            }

            usort($units, fn (array $a, array $b): int => Money::compare($b['price'], $a['price']));
            $groups = intdiv(count($units), $buy + $get);
            $amount = '0.00';
            for ($g = 0; $g < $groups; $g++) {
                $group = array_slice($units, $g * ($buy + $get), $buy + $get);
                foreach ($group as $unit) {
                    $used[$unit['line']] = ($used[$unit['line']] ?? 0) + 1;
                }
                foreach (array_slice($group, $buy) as $free) {
                    $amount = Money::add($amount, $free['price']);
                }
            }
            $add($promo, $amount);
        }

        $itemPromos = Money::add('0.00', ...array_column($applied, 'amount'));
        $afterItems = Money::max(Money::sub($subtotal, $itemPromos), '0.00');

        // Diskon minimal belanja: satu terbesar
        $best = null;
        $bestAmount = '0.00';
        foreach ($promotions->where('type', PromotionType::OrderDiscount) as $promo) {
            if ($promo->min_subtotal !== null && Money::compare($afterItems, $promo->min_subtotal) < 0) {
                continue;
            }
            $amount = $promo->discount_type === DiscountType::Percent
                ? Money::percentOf($afterItems, Money::min($promo->discount_value, '100.00'))
                : Money::min($promo->discount_value, $afterItems);
            if ($promo->discount_type === DiscountType::Percent && $promo->max_discount !== null) {
                $amount = Money::min($amount, $promo->max_discount);
            }
            if (Money::compare($amount, $bestAmount) > 0) {
                [$best, $bestAmount] = [$promo, $amount];
            }
        }
        if ($best !== null) {
            $add($best, $bestAmount);
        }

        $total = Money::min(Money::add('0.00', ...array_column($applied, 'amount')), $subtotal);

        return new PromoPricing($total, array_values($applied));
    }
}
