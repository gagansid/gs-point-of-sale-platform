<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItem;
use App\Models\OrderItemOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
final class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'unit_price' => $this->unit_price,
            'options_total' => $this->options_total,
            'qty' => $this->qty,
            'discount' => $this->discount,
            'line_total' => $this->line_total,
            'notes' => $this->notes,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn (OrderItemOption $o): array => [
                'option_id' => $o->option_id,
                'name' => $o->option_name,
                'price_delta' => $o->price_delta,
            ])->all()),
        ];
    }
}
