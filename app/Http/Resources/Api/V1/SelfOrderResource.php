<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\SelfOrder;
use App\Models\SelfOrderSetting;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pesanan dari meja untuk aplikasi kasir (ADR 0014, S4). Nama produk/opsi dari katalog saat ini (pesanan belum
 * di-snapshot); `estimated_total` hanya perkiraan — tagihan final dihitung saat diterima.
 *
 * @mixin SelfOrder
 */
final class SelfOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, string> $products */
        $products = $this->additional['products'] ?? [];
        /** @var array<string, string> $options */
        $options = $this->additional['options'] ?? [];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'table' => ['id' => $this->dining_table_id, 'name' => $this->diningTable->name ?? null],
            'customer_name' => $this->customer_name,
            'member' => $this->member_id !== null ? ['id' => $this->member_id, 'name' => $this->member->name ?? null] : null,
            'items' => array_map(fn (array $i): array => [
                'product_id' => $i['product_id'],
                'product_name' => $products[$i['product_id']] ?? null,
                'qty' => $i['qty'],
                'option_ids' => $i['option_ids'],
                'option_names' => array_values(array_filter(array_map(fn (string $id): ?string => $options[$id] ?? null, $i['option_ids']))),
                'notes' => $i['notes'],
            ], $this->items),
            'notes' => $this->notes,
            'voucher_code' => $this->voucher_code,
            'estimated_total' => $this->estimated_total,
            'reject_reason' => $this->reject_reason,
            'order_id' => $this->order_id,
            'created_at' => Iso::dateTime($this->created_at),
            'expires_at' => Iso::dateTime($this->created_at->addMinutes(SelfOrderSetting::forOutlet($this->outlet_id)->payment_timeout_minutes)),
        ];
    }
}
