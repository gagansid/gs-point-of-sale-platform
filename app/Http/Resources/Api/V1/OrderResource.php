<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type->value,
            'table_label' => $this->table_label,
            'status' => $this->status->value,
            // Status merged: bill tujuan (ADR 0019)
            'merged_into_id' => $this->merged_into_id,
            // Kanal & meja self-order (ADR 0014)
            'channel' => $this->channel->value,
            'dining_table_id' => $this->dining_table_id,
            'accepted_at' => Iso::dateTime($this->accepted_at),
            'refund_required' => $this->refund_required,
            'notes' => $this->notes,
            // Snapshot nama saat transaksi (ADR 0013)
            'member' => $this->member_id !== null ? ['id' => $this->member_id, 'name' => $this->member_name, 'tier' => $this->member_tier] : null,
            'points_earned' => $this->points_earned,
            'shift_id' => $this->shift_id,
            'user_id' => $this->user_id,
            'discount' => $this->discount_type !== null
                ? ['type' => $this->discount_type->value, 'value' => $this->discount_value]
                : null,
            'subtotal' => $this->subtotal,
            'order_discount' => $this->order_discount,
            'discount_total' => $this->discount_total,
            'member_discount' => $this->member_discount,
            // Gratis kartu stempel (M6): bagian dari discount_total
            'stamp_free_total' => $this->stamp_free_total,
            'stamp_redemptions' => $this->stamp_redemptions ?? [],
            'stamps_earned' => $this->stamps_earned,
            // Voucher (M7): bagian dari discount_total
            'voucher' => $this->voucher_id !== null ? ['id' => $this->voucher_id, 'code' => $this->voucher_code] : null,
            'voucher_discount' => $this->voucher_discount,
            'points_redeemed' => $this->points_redeemed,
            'service_total' => $this->service_total,
            'tax_total' => $this->tax_total,
            'rounding' => $this->rounding,
            'grand_total' => $this->grand_total,
            'paid_total' => $this->paid_total,
            // Retur sebagian (ADR 0018)
            'refunded_total' => $this->refunded_total,
            'refunds' => OrderRefundResource::collection($this->whenLoaded('refunds')),
            'change_total' => $this->change_total,
            'tax_inclusive' => $this->tax_inclusive,
            'approved_by' => $this->approved_by,
            'void_reason' => $this->void_reason,
            'voided_by' => $this->voided_by,
            'void_approved_by' => $this->void_approved_by,
            'voided_at' => Iso::dateTime($this->voided_at),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => Iso::dateTime($this->created_at),
            'completed_at' => Iso::dateTime($this->completed_at),
        ];
    }
}
