<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Akses Category (panel /dashboard). Memakai permission, tidak pernah role (ADR 0005).
 * Pengecekan tenant adalah pertahanan berlapis di atas TenantScope.
 * Melihat memakai hasPermission (tetap bisa saat hanya-baca), mengubah memakai can() (ADR 0009).
 */
final class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('product.manage');
    }

    public function view(User $user, Category $model): bool
    {
        return $this->owns($user, $model) && $user->hasPermission('product.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('product.manage');
    }

    public function update(User $user, Category $model): bool
    {
        return $this->owns($user, $model) && $user->can('product.manage');
    }

    public function delete(User $user, Category $model): bool
    {
        return $this->owns($user, $model) && $user->can('product.manage');
    }

    public function reorder(User $user): bool
    {
        return $user->can('product.manage');
    }

    private function owns(User $user, Category $model): bool
    {
        return $model->tenant_id === $user->tenant_id;
    }
}
