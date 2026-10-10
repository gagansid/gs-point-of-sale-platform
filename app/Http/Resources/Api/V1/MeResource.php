<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User login beserta tenant & outlet aktifnya (GET /auth/me, respons login).
 *
 * @mixin User
 */
final class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Outlet perangkat (ADR 0010); login tanpa perangkat: outlet pertama yang boleh diakses
        $outlet = CurrentOutlet::selectedId() !== null ? CurrentOutlet::get() : $this->accessibleOutlets()->first();

        return [
            'user' => UserResource::make($this->resource)->resolve($request),
            'tenant' => TenantResource::make($this->tenant)->resolve($request),
            'outlet' => $outlet !== null ? OutletResource::make($outlet)->resolve($request) : null,
            // Outlet aktif yang dipegang user (ADR 0010)
            'outlets' => OutletSummaryResource::collection($this->accessibleOutlets()->active()->get())->resolve($request),
        ];
    }
}
