<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CashMovement;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kas masuk/keluar shift (ADR 0017).
 *
 * @mixin CashMovement
 */
final class CashMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shift_id,
            'type' => $this->type->value,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->getLabel(),
            'amount' => $this->amount,
            'note' => $this->note,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'created_at' => Iso::dateTime($this->created_at),
        ];
    }
}
