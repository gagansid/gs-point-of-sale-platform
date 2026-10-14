<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Shift;
use App\Models\ShiftOperator;
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
            // ADR 0017: manual / carry_over / adjusted; carried_cash = kas awal semula sebelum dikoreksi
            'opening_cash_source' => $this->opening_cash_source->value,
            'carried_cash' => $this->carried_cash,
            'opening_cash_note' => $this->opening_cash_note,
            'expected_cash' => $this->expected_cash,
            'actual_cash' => $this->actual_cash,
            'difference' => $this->difference,
            'cash_deposited' => $this->cash_deposited,
            'closing_float' => $this->closing_float,
            'close_note' => $this->close_note,
            'opened_at' => Iso::dateTime($this->opened_at),
            'closed_at' => Iso::dateTime($this->closed_at),
            // Log operator (ganti kasir tanpa hitung)
            'operators' => $this->whenLoaded('operators', fn () => $this->operators->map(fn (ShiftOperator $operator): array => [
                'user_id' => $operator->user_id,
                'name' => $operator->user->name ?? null,
                'started_at' => Iso::dateTime($operator->started_at),
                'ended_at' => Iso::dateTime($operator->ended_at),
            ])->values()->all()),
        ];
    }
}
