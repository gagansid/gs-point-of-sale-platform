<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionGroup */
final class OptionGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'is_active' => $this->is_active,
            'options' => OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
