<?php

declare(strict_types=1);

namespace App\Actions\Member\Data;

/**
 * Benefit member yang dipilih kasir untuk satu order (ADR 0013): kartu stempel yang ditukar (M6).
 * Nominal tetap dihitung server (ResolveMemberPricing); ini hanya pilihan.
 */
final readonly class BenefitSelection
{
    /**
     * @param  list<string>  $stampProgramIds
     */
    public function __construct(
        public array $stampProgramIds = [],
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
        );
    }

    public function isEmpty(): bool
    {
        return $this->stampProgramIds === [];
    }
}
