<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Auth\VerifyPin;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Support\Phone;
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
 * - PIN diganti → penghitung & kunci PIN direset dan token API dicabut (sama dengan SaveEmployee).
 */
final class UpdateOwnProfile
{
    /**
     * @param  string|null  $password  kata sandi baru (boleh sudah di-hash oleh form; cast "hashed" tidak meng-hash ulang)
     * @param  string|null  $pin  PIN baru 6 digit (sudah divalidasi SecurePin); null = tidak diganti
     * @param  bool|null  $dailySummaryEmail  ringkasan harian email (Q57); null = tidak diubah
     * @param  string|false|null  $phone  nomor HP (format apa pun, dinormalisasi +62…); null = dikosongkan; false = tidak diubah
     *
     * @throws BusinessException nomor HP tidak valid
     */
    public function handle(User $user, string $name, string $email, #[SensitiveParameter] ?string $password = null, #[SensitiveParameter] ?string $pin = null, ?bool $dailySummaryEmail = null, string|false|null $phone = false): User
    {
        if ($phone !== false && $phone !== null && trim($phone) !== '') {
            $phone = Phone::normalize($phone) ?? throw BusinessException::of(ErrorCode::ValidationError, 'Nomor HP tidak valid. Contoh: 0812 3456 7890', ['phone' => ['Nomor HP tidak valid']]);
        }

        $user = DB::transaction(function () use ($user, $name, $email, $password, $pin, $dailySummaryEmail, $phone): User {
            $user->fill(['name' => $name, 'email' => Str::lower(trim($email))]);

            if ($phone !== false) {
                $user->phone = $phone !== null && trim($phone) !== '' ? $phone : null;
            }

            if ($dailySummaryEmail !== null) {
                $user->daily_summary_email = $dailySummaryEmail && $user->hasPermission('report.view');
            }

            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null]);
            }

            if ($password !== null) {
                $user->password = $password;
            }

            if ($pin !== null) {
                $user->pin = $pin;
            }

            $user->save();

            if ($pin !== null) {
                VerifyPin::resetFor($user);
            }

            if ($password !== null || $pin !== null) {
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
