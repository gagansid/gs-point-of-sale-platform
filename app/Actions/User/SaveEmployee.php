<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\User\Data\EmployeeData;
use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Menambah (employee = null) atau mengubah karyawan (SPEC: Karyawan, permission user.manage).
 *
 * - Owner/manager login email + kata sandi. Supervisor/kasir: PIN (tablet) + username & kata sandi
 *   (kasir web, cadangan bila tablet rusak — SPEC Q35).
 * - Mengganti PIN, kata sandi, atau role memutus semua sesi karyawan itu (token dihapus).
 * - Owner aktif terakhir tidak boleh diturunkan rolenya (LAST_OWNER_REQUIRED).
 */
final class SaveEmployee
{
    /**
     * @return array{user: User, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(?User $employee, EmployeeData $data): array
    {
        if ($employee === null && ($existing = Idempotency::existing(User::class, $data->id)) !== null) {
            return ['user' => $existing, 'replayed' => true];
        }

        $isNew = $employee === null;
        self::ensureCredentials($employee, $data);

        $user = DB::transaction(function () use ($employee, $data, $isNew): User {
            $user = $employee ?? new User;

            if ($isNew && $data->id !== null) {
                $user->id = $data->id;
            }

            $roleChanged = ! $isNew && $user->role !== $data->role;

            if ($roleChanged && $user->role === UserRole::Owner && $data->role !== UserRole::Owner) {
                self::ensureAnotherActiveOwner($user);
            }

            $user->fill([
                'name' => $data->name,
                'role' => $data->role,
                'email' => $data->email,
                'username' => $data->username,
                // Owner berlaku untuk seluruh outlet; role lain terikat outlet (MVP: satu outlet)
                'outlet_id' => $data->role === UserRole::Owner ? null : CurrentOutlet::getOrFail()->id,
            ]);

            if ($isNew) {
                $user->is_active = true;
                // Dibuat owner yang sudah login = email dipercaya (ADR 0009)
                $user->forceFill(['email_verified_at' => $data->email !== null ? now() : null]);
            }

            if ($data->password !== null) {
                $user->password = $data->password;
            }

            if ($data->pin !== null) {
                $user->pin = $data->pin;
                $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null]);
            }

            $user->save();

            // Akses lama langsung putus bila kredensial atau hak berubah
            if (! $isNew && ($roleChanged || $data->password !== null || $data->pin !== null)) {
                $user->tokens()->delete();
            }

            return $user;
        });

        return ['user' => $user->refresh(), 'replayed' => false];
    }

    /**
     * @throws BusinessException
     */
    private static function ensureCredentials(?User $employee, EmployeeData $data): void
    {
        $errors = [];

        if ($data->role->canUsePasswordLogin()) {
            if ($data->email === null) {
                $errors['email'] = ['Email wajib untuk owner & manager (login dashboard/aplikasi)'];
            }

            if ($data->password === null && ($employee === null || $employee->password === null)) {
                $errors['password'] = ['Kata sandi wajib untuk owner & manager'];
            }
        } else {
            if ($data->pin === null && ($employee === null || $employee->pin === null)) {
                $errors['pin'] = ['PIN wajib untuk supervisor & kasir (login di tablet)'];
            }

            if ($data->username === null) {
                $errors['username'] = ['Username wajib untuk supervisor & kasir (login kasir web)'];
            }

            if ($data->password === null && ($employee === null || $employee->password === null)) {
                $errors['password'] = ['Kata sandi wajib untuk supervisor & kasir (login kasir web)'];
            }
        }

        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, details: $errors);
        }
    }

    /**
     * @throws BusinessException LAST_OWNER_REQUIRED
     */
    public static function ensureAnotherActiveOwner(User $owner): void
    {
        $others = User::query()
            ->where('role', UserRole::Owner)
            ->where('is_active', true)
            ->whereKeyNot($owner->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw BusinessException::of(ErrorCode::LastOwnerRequired);
        }
    }
}
