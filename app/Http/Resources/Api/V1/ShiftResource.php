<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Shift;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shift */
final class ShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'device_id' => $this->device_id,
            'status' => $this->status->value,
            'opened_by' => $this->opened_by,
            'opened_by_name' => $this->whenLoaded('openedBy', fn () => $this->openedBy->name),
            'closed_by' => $this->closed_by,
            'opening_cash' => $this->opening_cash,
            'expected_cash' => $this->expected_cash,
            'actual_cash' => $this->actual_cash,
            'difference' => $this->difference,
            'close_note' => $this->close_note,
            'opened_at' => Iso::dateTime($this->opened_at),
            'closed_at' => Iso::dateTime($this->closed_at),
        ];
    }
}
