<?php

declare(strict_types=1);

namespace App\Actions\Order\Data;

use App\Actions\Auth\Data\ApprovalData;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Support\Money;

/**
 * Masukan checkout dari aplikasi. Hanya produk, opsi, qty, diskon, dan pembayaran;
 * semua nominal tagihan dihitung ulang server.
 */
final readonly class CheckoutData
{
    /**
     * @param  list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>  $items
     * @param  list<array{id: string, payment_method_id: string, amount: string, tendered: string|null, reference: string|null}>  $payments
     */
    public function __construct(
        public string $id,
        public OrderType $orderType,
        public ?string $tableLabel,
        public ?string $notes,
        public ?DiscountType $discountType,
        public string $discountValue,
        public array $items,
        public array $payments,
        public ?ApprovalData $approval,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $discount = $data['discount'] ?? null;

        return new self(
            id: (string) $data['id'],
            orderType: OrderType::from((string) $data['order_type']),
            tableLabel: filled($data['table_label'] ?? null) ? (string) $data['table_label'] : null,
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
            discountType: is_array($discount) ? DiscountType::from((string) $discount['type']) : null,
            discountValue: is_array($discount) ? Money::of((string) $discount['value']) : '0.00',
            items: self::parseItems($data['items'] ?? []),
            payments: self::parsePayments($data['payments'] ?? []),
            approval: self::parseApproval($data),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>
     */
    public static function parseItems(array $items): array
    {
        $parsed = [];
        foreach ($items as $item) {
            $parsed[] = [
                'product_id' => (string) $item['product_id'],
                'qty' => (int) $item['qty'],
                // Duplikat dalam satu item dibuang (validasi tidak memakai "distinct", lihat CheckoutRequest)
                'option_ids' => array_values(array_unique(array_map('strval', $item['option_ids'] ?? []))),
                'notes' => filled($item['notes'] ?? null) ? (string) $item['notes'] : null,
                'discount' => Money::of((string) ($item['discount'] ?? '0')),
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return list<array{id: string, payment_method_id: string, amount: string, tendered: string|null, reference: string|null}>
     */
    public static function parsePayments(array $payments): array
    {
        $parsed = [];
        foreach ($payments as $payment) {
            $parsed[] = [
                'id' => (string) $payment['id'],
                'payment_method_id' => (string) $payment['payment_method_id'],
                'amount' => Money::of((string) $payment['amount']),
                'tendered' => isset($payment['tendered']) ? Money::of((string) $payment['tendered']) : null,
                'reference' => filled($payment['reference'] ?? null) ? trim((string) $payment['reference']) : null,
            ];
        }

        return $parsed;
    }

    /** @param array<string, mixed> $data */
    public static function parseApproval(array $data): ?ApprovalData
    {
        return filled($data['approver_user_id'] ?? null)
            ? new ApprovalData((string) $data['approver_user_id'], (string) ($data['approver_pin'] ?? ''))
            : null;
    }
}
