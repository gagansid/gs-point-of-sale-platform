<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Outlet;
use App\Models\User;
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
        // MVP 1 outlet per bisnis: owner (outlet_id null) memakai outlet pertama tenant
        $outlet = $this->outlet_id !== null
            ? Outlet::forTenant($this->tenant_id)->find($this->outlet_id)
            : Outlet::forTenant($this->tenant_id)->orderBy('created_at')->first();

        return [
            'user' => UserResource::make($this->resource)->resolve($request),
            'tenant' => TenantResource::make($this->tenant)->resolve($request),
            'outlet' => $outlet !== null ? OutletResource::make($outlet)->resolve($request) : null,
        ];
    }
}
