<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Device;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'name' => $this->name,
            'device_uid' => $this->device_uid,
            'platform' => $this->platform?->value,
            'app_version' => $this->app_version,
            'last_seen_at' => Iso::dateTime($this->last_seen_at),
        ];
    }
}
