<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Kelola akun super admin dari panel (Sistem → Admin). Membuat akun memakai CreateAdmin.
 * Pengaman: tidak bisa menghapus/mereset 2FA diri sendiri, dan minimal satu admin tersisa.
 */
final class ManageAdmin
{
    /**
     * Ubah nama/email; kata sandi baru opsional (sesi admin itu keluar lewat AuthenticateSession).
     *
     * @throws ValidationException
     */
    public function update(Admin $admin, string $name, string $email, ?string $password): Admin
    {
        $data = Validator::make(['name' => $name, 'email' => $email, 'password' => $password], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin->id)],
            'password' => ['nullable', Password::defaults()->min(12)->mixedCase()->symbols()],
        ])->validate();

        $admin->fill(['name' => $data['name'], 'email' => strtolower((string) $data['email'])]);

        if (filled($data['password'])) {
            $admin->password = (string) $data['password'];
        }

        $admin->save();

        return $admin;
    }

    /**
     * Hapus 2FA admin lain (HP hilang): ia wajib mengatur ulang saat login berikutnya bila 2FA wajib.
     *
     * @throws BusinessException
     */
    public function resetTwoFactor(Admin $actor, Admin $admin): Admin
    {
        if ($actor->is($admin)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Atur 2FA Anda sendiri dari halaman profil');
        }

        $admin->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();

        return $admin;
    }

    /** @throws BusinessException */
    public function delete(Admin $actor, Admin $admin): void
    {
        if ($actor->is($admin)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Tidak bisa menghapus akun Anda sendiri');
        }

        DB::transaction(function () use ($admin): void {
            // Kunci semua baris: dua penghapusan bersamaan tidak boleh menyisakan nol admin
            if (Admin::query()->lockForUpdate()->count() <= 1) {
                throw BusinessException::of(ErrorCode::Forbidden, 'Minimal harus ada satu super admin');
            }

            $admin->delete();
        });
    }
}
