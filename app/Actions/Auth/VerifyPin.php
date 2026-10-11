<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Memeriksa PIN 6 digit dengan penguncian bertingkat (SPEC Keamanan, GSPOS-SEC-002).
 * Dipakai login PIN dan PIN approver (void, diskon di atas batas).
 *
 * Penghitung per jendela `lockout_minutes`:
 * - per (user, sumber) — sumber = "login:{device}" / "approval:{device}": `max_attempts` salah → PIN user
 *   dikunci HANYA untuk sumber itu. Perangkat dicuri / kasir jahat tidak bisa mengunci login karyawan
 *   di perangkat lain, dan tebakan PIN approver tidak mengunci login PIN-nya.
 * - per user (semua sumber): `max_attempts_total` salah → dikunci di semua perangkat (pin_locked_until,
 *   terlihat & bisa dibuka owner). Membatasi brute force memakai banyak perangkat.
 * - per perangkat (semua user): `max_attempts_per_device` salah → TOO_MANY_REQUESTS, agar satu perangkat
 *   tidak bisa menebak PIN banyak karyawan bergiliran.
 *
 * Percobaan dicatat SEBELUM hash dicek ("dipesan"), sehingga request paralel tetap terhitung.
 * Unlock owner / ganti PIN mereset semua penghitung lewat epoch per user (resetFor()).
 */
final class VerifyPin
{
    /**
     * @param  string  $source  asal percobaan: "login:{device_id}" atau "approval:{device_id}"
     * @param  string|null  $deviceId  perangkat pengirim (batas per perangkat); null = tanpa batas perangkat
     *
     * @throws BusinessException INVALID_PIN, PIN_LOCKED, atau TOO_MANY_REQUESTS
     */
    public function handle(User $user, string $pin, string $source, ?string $deviceId = null): void
    {
        $decay = (int) config('pos.pin.lockout_minutes') * 60;
        $perSource = (int) config('pos.pin.max_attempts');
        $perUser = (int) config('pos.pin.max_attempts_total');
        $perDevice = (int) config('pos.pin.max_attempts_per_device');

        $user->refresh();

        if ($user->isPinLocked()) {
            self::throwLocked((int) now()->diffInSeconds($user->pin_locked_until, true));
        }

        $deviceKey = $deviceId !== null ? 'pin-device:'.$deviceId : null;

        if ($deviceKey !== null && RateLimiter::tooManyAttempts($deviceKey, $perDevice)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu banyak PIN salah di perangkat ini. Coba lagi dalam '
                .(int) ceil(RateLimiter::availableIn($deviceKey) / 60).' menit.');
        }

        $sourceKey = self::key($user, $source);
        $userKey = self::key($user, 'all');

        // Kunci per sumber masih berlaku: jangan cek hash sama sekali
        if (RateLimiter::tooManyAttempts($sourceKey, $perSource)) {
            self::throwLocked(RateLimiter::availableIn($sourceKey));
        }

        $sourceHits = RateLimiter::hit($sourceKey, $decay);
        $userHits = RateLimiter::hit($userKey, $decay);

        // Percobaan paralel yang melewati batas setelah "dipesan" tetap ditolak
        if ($sourceHits > $perSource) {
            self::throwLocked(RateLimiter::availableIn($sourceKey));
        }

        if ($user->pin !== null && Hash::check($pin, $user->pin)) {
            self::resetFor($user);

            return;
        }

        if ($deviceKey !== null) {
            RateLimiter::hit($deviceKey, $decay);
        }

        // Kunci global: semua perangkat (dibuka owner atau setelah lockout_minutes)
        if ($userHits >= $perUser) {
            $user->forceFill(['pin_failed_attempts' => $userHits, 'pin_locked_until' => now()->addSeconds($decay)])->save();
            Log::warning('PIN dikunci di semua perangkat', ['user_id' => $user->id, 'source' => $source, 'attempts' => $userHits]);

            self::throwLocked($decay);
        }

        $user->forceFill(['pin_failed_attempts' => $userHits])->save();

        if ($sourceHits >= $perSource) {
            Log::warning('PIN dikunci untuk satu sumber', ['user_id' => $user->id, 'source' => $source]);

            self::throwLocked(RateLimiter::availableIn($sourceKey));
        }

        throw BusinessException::of(ErrorCode::InvalidPin);
    }

    /** Reset semua penghitung & kunci PIN user (PIN benar, unlock owner, PIN diganti). */
    public static function resetFor(User $user): void
    {
        // Epoch baru → semua kunci per sumber lama tidak terpakai lagi
        Cache::forget(self::epochKey($user));

        if ($user->pin_failed_attempts !== 0 || $user->pin_locked_until !== null) {
            $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
        }
    }

    private static function key(User $user, string $source): string
    {
        $epoch = Cache::rememberForever(self::epochKey($user), fn (): string => Str::random(12));

        return 'pin:'.$user->id.':'.$epoch.':'.$source;
    }

    private static function epochKey(User $user): string
    {
        return 'pin-epoch:'.$user->id;
    }

    /** @throws BusinessException */
    private static function throwLocked(int $seconds): never
    {
        throw BusinessException::of(ErrorCode::PinLocked, details: ['retry_after' => max(1, $seconds)]);
    }
}
