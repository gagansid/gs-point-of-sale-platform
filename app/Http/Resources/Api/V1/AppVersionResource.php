<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppVersion */
final class AppVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'platform' => $this->platform->value,
            'min_version' => $this->min_version,
            'latest_version' => $this->latest_version,
            'force_update' => $this->force_update,
        ];
    }
}
