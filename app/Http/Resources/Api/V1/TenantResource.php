<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tenant;
use App\Support\Features;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_type' => $this->business_type->value,
            'status' => $this->status->value,
            'subscription_ends_at' => Iso::dateTime($this->subscription_ends_at),
            // Fitur paket yang aktif (ADR 0025): Flutter menyembunyikan menu fitur yang tidak ada di daftar
            'features' => Features::enabledKeys($this->resource),
        ];
    }
}
