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
            'notes' => $this->notes,
            'shift_id' => $this->shift_id,
            'user_id' => $this->user_id,
            'discount' => $this->discount_type !== null
                ? ['type' => $this->discount_type->value, 'value' => $this->discount_value]
                : null,
            'subtotal' => $this->subtotal,
            'order_discount' => $this->order_discount,
            'discount_total' => $this->discount_total,
            'service_total' => $this->service_total,
            'tax_total' => $this->tax_total,
            'rounding' => $this->rounding,
            'grand_total' => $this->grand_total,
            'paid_total' => $this->paid_total,
            'change_total' => $this->change_total,
            'tax_inclusive' => $this->tax_inclusive,
            'approved_by' => $this->approved_by,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => Iso::dateTime($this->created_at),
            'completed_at' => Iso::dateTime($this->completed_at),
        ];
    }
}
