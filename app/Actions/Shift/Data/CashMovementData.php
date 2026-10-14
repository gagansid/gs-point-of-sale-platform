<?php

declare(strict_types=1);

namespace App\Actions\Shift\Data;

use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Support\Money;

/** Kas masuk/keluar dari API maupun kasir web (field sudah tervalidasi), ADR 0017. */
final readonly class CashMovementData
{
    public function __construct(
        // ID dari klien = idempotency key
        public string $id,
        public CashMovementType $type,
        public CashMovementReason $reason,
        public string $amount,
        public ?string $note,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: CashMovementType::from((string) $data['type']),
            reason: CashMovementReason::from((string) $data['reason']),
            amount: Money::of((string) $data['amount']),
            note: filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
        );
    }
}
