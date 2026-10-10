<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->getLabel(),
            // Untuk menampilkan/menyembunyikan menu di app; keputusan akhir tetap di server
            'permissions' => $this->role->grantedPermissions(),
            'outlet_ids' => $this->outletIds(),
            'last_login_at' => Iso::dateTime($this->last_login_at),
        ];
    }
}
