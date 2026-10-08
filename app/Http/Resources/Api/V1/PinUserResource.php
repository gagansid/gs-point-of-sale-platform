<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data minimal untuk layar pilih nama sebelum login PIN (tanpa email/PIN).
 *
 * @mixin User
 */
final class PinUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role->value,
            'role_label' => $this->role->getLabel(),
        ];
    }
}
