<?php

declare(strict_types=1);

namespace App\Actions\Member\Data;

/**
 * Benefit yang dipilih kasir untuk satu order (ADR 0013): kartu stempel yang ditukar (M6) dan voucher (M7, kode
 * promo atau ID voucher pribadi). Nominal tetap dihitung server (ResolveMemberPricing); ini hanya pilihan.
 */
final readonly class BenefitSelection
{
    /**
     * @param  list<string>  $stampProgramIds
     */
    public function __construct(
        public array $stampProgramIds = [],
        public ?string $voucherCode = null,
        public ?string $voucherId = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            stampProgramIds: array_values(array_unique(array_map('strval', (array) ($data['stamp_program_ids'] ?? [])))),
            voucherCode: filled($data['voucher_code'] ?? null) ? trim((string) $data['voucher_code']) : null,
            voucherId: filled($data['voucher_id'] ?? null) ? (string) $data['voucher_id'] : null,
        );
    }

    public function hasVoucher(): bool
    {
        return $this->voucherCode !== null || $this->voucherId !== null;
    }

    public function isEmpty(): bool
    {
        return $this->stampProgramIds === [] && ! $this->hasVoucher();
    }
}
