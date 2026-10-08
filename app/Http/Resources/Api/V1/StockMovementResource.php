<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\StockMovement;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
final class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'type' => $this->type->value,
            'qty_change' => $this->qty_change,
            'qty_after' => $this->qty_after,
            'reason' => $this->reason,
            'created_at' => Iso::dateTime($this->created_at),
        ];
    }
}
