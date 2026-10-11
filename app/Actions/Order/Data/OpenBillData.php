<?php

declare(strict_types=1);

namespace App\Actions\Order\Data;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Member\Data\BenefitSelection;
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
        // Member (ADR 0013): memberProvided = false (field tidak dikirim) → member lama tetap; null = dilepas
        public ?string $memberId = null,
        public bool $memberProvided = false,
        // Benefit member (kartu stempel, M6): benefitsProvided = false → pilihan lama di open bill tetap
        public BenefitSelection $benefits = new BenefitSelection,
        public bool $benefitsProvided = false,
        // Voucher (M7): voucherProvided = false → voucher lama di open bill tetap; null = dilepas
        public bool $voucherProvided = false,
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
            memberId: filled($data['member_id'] ?? null) ? (string) $data['member_id'] : null,
            memberProvided: array_key_exists('member_id', $data),
            benefits: BenefitSelection::fromArray($data),
            benefitsProvided: array_key_exists('stamp_program_ids', $data),
            voucherProvided: array_key_exists('voucher_code', $data) || array_key_exists('voucher_id', $data),
        );
    }
}
