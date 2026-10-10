<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Karyawan untuk Pengaturan → Karyawan. PIN & kata sandi tidak pernah dikirim; hanya statusnya.
 *
 * @mixin User
 */
final class EmployeeResource extends JsonResource
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
            'outlet_id' => $this->outlet_id,
            'is_active' => $this->is_active,
            'has_pin' => $this->pin !== null,
            'pin_locked_until' => Iso::dateTime($this->pin_locked_until),
            'last_login_at' => Iso::dateTime($this->last_login_at),
            'created_at' => Iso::dateTime($this->created_at),
        ];
    }
}
