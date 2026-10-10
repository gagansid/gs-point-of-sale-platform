<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Option */
final class OptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_delta' => $this->price_delta,
            'sort_order' => $this->sort_order,
            // Habis di outlet device (ADR 0011 / Q43); diisi GetCatalog, bawaan tersedia
            'is_available' => (bool) ($this->resource->getAttributes()['is_available'] ?? true),
        ];
    }
}
