<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ringkasan outlet untuk daftar & pemilih outlet (GET /outlets, /auth/me), ADR 0010.
 *
 * @mixin Outlet
 */
final class OutletSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
        ];
    }
}
