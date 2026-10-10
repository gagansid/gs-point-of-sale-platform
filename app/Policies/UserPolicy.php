<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Akses karyawan (Pengaturan → Karyawan). Memakai permission, tidak pernah role (ADR 0005).
 * Melihat memakai hasPermission (tetap bisa saat hanya-baca), mengubah memakai can() (ADR 0009).
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('user.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $this->owns($user, $model) && $user->hasPermission('user.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $this->owns($user, $model) && $user->can('user.manage');
    }

    /** Karyawan tidak dihapus: dinonaktifkan agar riwayat transaksi tetap utuh. */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    private function owns(User $user, User $model): bool
    {
        return $model->tenant_id === $user->tenant_id;
    }
}
