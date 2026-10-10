<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aktifkan/nonaktifkan karyawan. Nonaktif = semua sesi langsung putus & tidak bisa login.
 * Tidak bisa menonaktifkan diri sendiri maupun owner aktif terakhir.
 */
final class SetEmployeeActive
{
    /**
     * @throws BusinessException
     */
    public function handle(User $employee, bool $active, User $by): User
    {
        if (! $active && $employee->is($by)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tidak bisa menonaktifkan akun sendiri', [
                'is_active' => ['Tidak bisa menonaktifkan akun sendiri'],
            ]);
        }

        DB::transaction(function () use ($employee, $active): void {
            if (! $active && $employee->role === UserRole::Owner && $employee->is_active) {
                SaveEmployee::ensureAnotherActiveOwner($employee);
            }

            $employee->forceFill(['is_active' => $active])->save();

            if (! $active) {
                $employee->tokens()->delete();
            }
        });

        return $employee;
    }
}
