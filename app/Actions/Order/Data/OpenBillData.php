<?php

declare(strict_types=1);

namespace App\Actions\Order\Data;

use App\Actions\Auth\Data\ApprovalData;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Support\Money;

/**
 * Isi open bill (dine-in): item selalu dikirim lengkap dan menggantikan item lama.
 */
final readonly class OpenBillData
{
    /**
     * @param  list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>  $items
     */
    public function __construct(
        public OrderType $orderType,
        public ?string $tableLabel,
        public ?string $notes,
        public ?DiscountType $discountType,
        public string $discountValue,
        public array $items,
        public ?ApprovalData $approval,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $discount = $data['discount'] ?? null;

        return new self(
            orderType: OrderType::from((string) ($data['order_type'] ?? OrderType::DineIn->value)),
            tableLabel: filled($data['table_label'] ?? null) ? (string) $data['table_label'] : null,
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
            discountType: is_array($discount) ? DiscountType::from((string) $discount['type']) : null,
            discountValue: is_array($discount) ? Money::of((string) $discount['value']) : '0.00',
            items: CheckoutData::parseItems($data['items'] ?? []),
            approval: CheckoutData::parseApproval($data),
        );
    }
}
