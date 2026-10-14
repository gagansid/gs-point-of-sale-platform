<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pilihan approver PIN di kasir web: karyawan aktif ber-PIN selain pelaku, yang punya izin aksi dan
 * ditugaskan di outlet itu (sama dengan pengecekan VerifyApproval).
 */
final class PinApprovers
{
    /** @return Collection<int, User> kosong bila pelaku sudah punya izin sendiri */
    public static function for(User $actor, string $permission, string $outletId): Collection
    {
        if ($actor->can($permission)) {
            return collect();
        }

        return User::query()->with('tenant')->where('is_active', true)->whereNotNull('pin')
            ->whereKeyNot($actor->id)->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->can($permission) && $user->canAccessOutlet($outletId))
            ->values();
    }
}
