<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Hash pembanding saat email login tidak terdaftar, agar waktu respons sama dengan kata sandi
 * salah (anti enumerasi akun, GSPOS-SEC-010).
 *
 * Hash dibuat sekali lalu disimpan di cache. Membuatnya per request (Hash::make) menambah satu
 * kali bcrypt hanya pada jalur "email tidak ada", sehingga selisih waktunya justru membocorkan
 * keberadaan akun. Kunci cache memuat cost bcrypt agar ikut berganti bila BCRYPT_ROUNDS diubah.
 */
final class DummyPasswordHash
{
    private static ?string $memo = null;

    public static function get(): string
    {
        $key = 'auth:dummy-password-hash:'.config('hashing.bcrypt.rounds');

        return self::$memo ??= Cache::rememberForever($key, fn (): string => Hash::make(bin2hex(random_bytes(16))));
    }
}
