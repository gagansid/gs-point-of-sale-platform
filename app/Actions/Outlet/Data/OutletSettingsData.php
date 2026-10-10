<?php

declare(strict_types=1);

namespace App\Actions\Outlet\Data;

use App\Support\Money;

/**
 * Setelan outlet dari API maupun form dashboard (field sudah tervalidasi).
 * Kode outlet tidak termasuk: awalan nomor order, tidak boleh diubah.
 */
final readonly class OutletSettingsData
{
    /**
     * @param  array{cashier: int|float, supervisor: int|float}  $discountLimits  persen per role (Q18)
     */
    public function __construct(
        public string $name,
        public ?string $address,
        public string $timezone,
        public string $taxRate,
        public bool $taxInclusive,
        public string $serviceChargeRate,
        public int $rounding,
        public ?string $receiptHeader,
        public ?string $receiptFooter,
        public array $discountLimits,
        // Q48: baris pembulatan di struk (bawaan tidak, digabung ke total)
        public bool $receiptShowRounding = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $optional = fn (string $key): ?string => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        $limits = (array) ($data['discount_limits'] ?? []);

        return new self(
            name: trim((string) $data['name']),
            address: $optional('address'),
            timezone: (string) $data['timezone'],
            taxRate: Money::of((string) $data['tax_rate']),
            taxInclusive: (bool) $data['tax_inclusive'],
            serviceChargeRate: Money::of((string) $data['service_charge_rate']),
            rounding: (int) $data['rounding'],
            receiptHeader: $optional('receipt_header'),
            receiptFooter: $optional('receipt_footer'),
            discountLimits: [
                'cashier' => 0 + ($limits['cashier'] ?? 0),
                'supervisor' => 0 + ($limits['supervisor'] ?? 0),
            ],
            receiptShowRounding: (bool) ($data['receipt_show_rounding'] ?? false),
        );
    }
}
