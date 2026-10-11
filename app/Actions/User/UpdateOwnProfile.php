<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Owner/manager mengubah profil sendiri (halaman Profil dashboard).
 *
 * - Email disimpan huruf kecil (login mencari huruf kecil). Email diganti = belum terverifikasi; link
 *   verifikasi dikirim ke alamat baru setelah tersimpan (SPEC Q46, GSPOS-SEC-012).
 * - Kata sandi diganti → semua token API (aplikasi Flutter) dicabut. Sesi web lain diputus oleh
 *   AuthenticateSession; sesi saat ini diperbarui Filament (GSPOS-SEC-013).
 */
final class UpdateOwnProfile
{
    /**
     * @param  string|null  $password  kata sandi baru (boleh sudah di-hash oleh form; cast "hashed" tidak meng-hash ulang)
     */
    public function handle(User $user, string $name, string $email, #[SensitiveParameter] ?string $password = null): User
    {
        $user = DB::transaction(function () use ($user, $name, $email, $password): User {
            $user->fill(['name' => $name, 'email' => Str::lower(trim($email))]);

            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null]);
            }

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();

            if ($password !== null) {
                $user->tokens()->delete();
            }

            return $user;
        });

        if ($user->wasChanged('email')) {
            // Setelah transaksi luar (halaman Filament) commit: email tidak terkirim bila penyimpanan batal
            DB::afterCommit(fn () => rescue(fn () => $user->notify(new VerifyOwnerEmail)));
        }

        return $user;
    }
}
