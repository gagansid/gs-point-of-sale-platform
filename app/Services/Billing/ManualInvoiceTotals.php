<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Support\Money;

/**
 * Hitung tagihan manual admin (satu-satunya tempat): baris = qty × harga satuan, subtotal, diskon nominal, PPN
 * persen dari (subtotal − diskon) dibulatkan ke rupiah penuh (half-up), total. Dipakai pratinjau form & CreateManualInvoice.
 */
final readonly class ManualInvoiceTotals
{
    /**
     * @param  list<array{type: string, addon_id: string|null, name: string, quantity: int, unit_price: string, amount: string}>  $lines
     */
    private function __construct(
        public array $lines,
        public string $subtotal,
        public string $discount,
        public string $taxRate,
        public string $tax,
        public string $total,
    ) {}

    /**
     * @param  iterable<mixed>  $items  baris mentah dari form: type, addon_id, name, quantity, unit_price
     */
    public static function of(iterable $items, mixed $discount = null, mixed $taxRate = null): self
    {
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $unit = self::money($item['unit_price'] ?? null);
            $lines[] = [
                'type' => (string) ($item['type'] ?? 'custom'),
                'addon_id' => filled($item['addon_id'] ?? null) ? (string) $item['addon_id'] : null,
                'name' => trim((string) ($item['name'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => $unit,
                'amount' => Money::mul($unit, $quantity),
            ];
        }

        $subtotal = Money::add(...array_column($lines, 'amount'));
        $discount = self::money($discount);
        $rate = is_numeric($taxRate) ? Money::round((string) $taxRate) : '0.00';
        $taxable = Money::sub($subtotal, $discount);
        $tax = bccomp($taxable, '0', 2) > 0 ? Money::of(Money::round(bcdiv(bcmul($taxable, $rate, 4), '100', 4), 0)) : '0.00';

        return new self($lines, $subtotal, $discount, $rate, $tax, Money::add($taxable, $tax));
    }

    /** Nilai sudah dinormalkan ("22000" / "22000.50"); selain angka ≥ 0 dianggap 0. */
    private static function money(mixed $value): string
    {
        return is_numeric($value) && (float) $value >= 0 ? Money::of(Money::round((string) $value)) : '0.00';
    }
}
