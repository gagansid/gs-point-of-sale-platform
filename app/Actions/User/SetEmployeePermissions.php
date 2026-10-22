<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Support\Facades\DB;

/**
 * Owner mengatur hak akses satu karyawan (ADR 0026, opsi B). Input = daftar izin yang diinginkan; disimpan sebagai
 * selisih terhadap hak akses role-nya (tambahan & dicabut), sehingga perubahan hak akses role tetap ikut berlaku untuk
 * izin lain. Sama dengan role = pengecualian dihapus. Owner tidak bisa diberi pengecualian.
 */
final class SetEmployeePermissions
{
    /**
     * @param  list<string>|null  $permissions  null = ikuti role
     * @return list<string> hak akses yang berlaku setelah disimpan
     *
     * @throws BusinessException FORBIDDEN, VALIDATION_ERROR
     */
    public function handle(User $by, User $employee, ?array $permissions): array
    {
        if (! $by->can('access.manage') || $employee->tenant_id !== $by->tenant_id) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        if (! $employee->role->isConfigurable()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Hak akses owner tidak bisa diubah', [
                'permissions' => ['Hak akses owner tidak bisa diubah'],
            ]);
        }

        $invalid = array_values(array_filter($permissions ?? [], fn (string $p): bool => ! UserRole::isDelegable($p)));

        if ($invalid !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Ada hak akses yang tidak bisa diberikan', [
                'permissions' => array_map(fn (string $p): string => "{$p} tidak bisa diberikan ke karyawan", $invalid),
            ]);
        }

        $role = RolePermissions::granted($employee->role, $employee->tenant_id);
        $wanted = $permissions === null ? $role : UserRole::normalize($permissions);
        $granted = array_values(array_diff($wanted, $role));
        $revoked = array_values(array_diff($role, $wanted));

        DB::transaction(fn () => $employee->forceFill([
            'permissions_granted' => $granted === [] ? null : $granted,
            'permissions_revoked' => $revoked === [] ? null : $revoked,
        ])->save());

        return $employee->grantedPermissions();
    }
}
