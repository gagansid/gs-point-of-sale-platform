<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Member;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Member untuk kasir (ADR 0013): nomor HP disamarkan; data pribadi lengkap hanya untuk member.manage.
 *
 * @mixin Member
 */
final class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $full = $user instanceof User && $user->can('member.manage');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone_masked' => Phone::masked($this->phone),
            'is_active' => $this->is_active,
            'points_balance' => $this->points_balance,
            // Kasbon (ADR 0023): saldo & batas yang berlaku (null = tidak boleh kasbon)
            'receivable_balance' => $this->receivable_balance,
            'credit_limit' => $this->effectiveCreditLimit(),
            // Tier saat ini (M5); null = di bawah tier terendah / belum ada tier
            'tier' => $this->tier !== null ? [
                'id' => $this->tier->id,
                'name' => $this->tier->name,
                'point_multiplier' => $this->tier->point_multiplier,
                'discount_percent' => $this->tier->discount_percent,
            ] : null,
            'visit_count' => $this->visit_count,
            'last_visit_at' => $this->last_visit_at?->toIso8601ZuluString(),
            'joined_at' => $this->created_at?->toIso8601ZuluString(),
            ...$full ? [
                'phone' => $this->phone,
                'email' => $this->email,
                'birth_day' => $this->birth_day,
                'birth_month' => $this->birth_month,
                'notes' => $this->notes,
            ] : [],
        ];
    }
}
