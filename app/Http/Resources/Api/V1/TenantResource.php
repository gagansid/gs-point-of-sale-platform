<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tenant;
use App\Support\Features;
use App\Support\Iso;
use App\Support\Quotas;
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
            // Kuota paket (ADR 0025): { key: { limit (null = tanpa batas), used } }
            'limits' => collect(Quotas::usage($this->resource))
                ->mapWithKeys(fn (array $row): array => [$row['key'] => ['limit' => $row['limit'], 'used' => $row['used']]])
                ->all(),
        ];
    }
}
