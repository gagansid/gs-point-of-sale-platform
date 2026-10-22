<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Owner mengatur hak akses role manager, supervisor, atau kasir di bisnisnya (ADR 0026). Permission yang tersirat
 * ikut diberikan (UserRole::IMPLIES); permission khusus owner tidak bisa diberikan. Berlaku di request berikutnya
 * tanpa logout. `null` = kembali ke bawaan role.
 */
final class UpdateRolePermissions
{
    /**
     * @param  list<string>|null  $permissions  null = kembali ke bawaan
     * @return list<string> permission yang berlaku setelah disimpan
     *
     * @throws BusinessException FORBIDDEN, VALIDATION_ERROR
     */
    public function handle(User $by, UserRole $role, ?array $permissions): array
    {
        if (! $by->can('access.manage')) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        if (! $role->isConfigurable()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Hak akses owner tidak bisa diubah', [
                'role' => ['Hak akses owner tidak bisa diubah'],
            ]);
        }

        if ($permissions !== null) {
            $invalid = array_values(array_filter($permissions, fn (string $p): bool => ! UserRole::isDelegable($p)));

            if ($invalid !== []) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Ada hak akses yang tidak bisa diberikan', [
                    'permissions' => array_map(fn (string $p): string => "{$p} tidak bisa diberikan ke role ini", $invalid),
                ]);
            }
        }

        DB::transaction(function () use ($role, $permissions): void {
            if ($permissions === null) {
                RolePermission::query()->where('role', $role->value)->first()?->delete();

                return;
            }

            // updateOrCreate → createOrFirst: dua simpan pertama yang bersamaan tidak gagal karena unique(tenant_id, role)
            RolePermission::query()->updateOrCreate(['role' => $role->value], ['permissions' => UserRole::normalize($permissions)]);
        });

        RolePermissions::flush();

        return RolePermissions::granted($role, TenantContext::idOrFail());
    }
}
