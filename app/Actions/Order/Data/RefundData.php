<?php

declare(strict_types=1);

namespace App\Actions\Order\Data;

use App\Enums\RefundMethod;

/** Retur sebagian dari API maupun kasir web (field sudah tervalidasi), ADR 0018. */
final readonly class RefundData
{
    /**
     * @param  array<string, int>  $items  order_item_id => qty (baris kembar sudah dijumlahkan)
     */
    public function __construct(
        // ID dari klien = idempotency key
        public string $id,
        public array $items,
        public string $reason,
        public RefundMethod $method,
        public bool $restock,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $row) {
            $id = (string) ($row['order_item_id'] ?? '');
            $items[$id] = ($items[$id] ?? 0) + (int) ($row['qty'] ?? 0);
        }

        return new self(
            id: (string) $data['id'],
            items: array_filter($items, fn (int $qty): bool => $qty > 0),
            reason: trim((string) $data['reason']),
            method: RefundMethod::from((string) ($data['method'] ?? RefundMethod::Cash->value)),
            restock: (bool) ($data['restock'] ?? true),
        );
    }
}
