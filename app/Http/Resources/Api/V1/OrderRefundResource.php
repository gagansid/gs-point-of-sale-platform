<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Retur sebagian (ADR 0018).
 *
 * @mixin OrderRefund
 */
final class OrderRefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'shift_id' => $this->shift_id,
            'reason' => $this->reason,
            'method' => $this->method->value,
            'restock' => $this->restock,
            'amount' => $this->amount,
            'payout_amount' => $this->payout_amount,
            'points_returned' => $this->points_returned,
            'points_reversed' => $this->points_reversed,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderRefundItem $item): array => [
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->product_name,
                'qty' => $item->qty,
                'amount' => $item->amount,
            ])->values()->all()),
            'created_at' => Iso::dateTime($this->created_at),
        ];
    }
}
