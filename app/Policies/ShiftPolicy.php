<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

/**
 * Melihat shift: shift yang pernah dipegang (pembuka atau operator, ADR 0017), atau semua shift bila punya
 * shift.view_all.
 */
final class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('shift.view_all');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $shift->tenant_id === $user->tenant_id
            && ($user->can('shift.view_all') || $shift->wasOperatedBy($user));
    }
}
