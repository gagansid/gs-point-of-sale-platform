<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Memeriksa PIN 6 digit dengan penguncian: 5 kali salah → dikunci 15 menit (SPEC Keamanan).
 * Dipakai login PIN dan PIN approver (void, diskon di atas batas).
 */
final class VerifyPin
{
    /**
     * @throws BusinessException INVALID_PIN atau PIN_LOCKED
     */
    public function handle(User $user, string $pin): void
    {
        // Hasil dihitung di dalam transaksi (baris user dikunci agar percobaan paralel tetap
        // terhitung), exception dilempar SETELAH commit agar hitungan gagal tidak ikut di-rollback.
        $outcome = DB::transaction(function () use ($user, $pin): string {
            $locked = User::allTenants()->lockForUpdate()->findOrFail($user->id);

            if ($locked->isPinLocked()) {
                return 'locked';
            }

            if ($locked->pin !== null && Hash::check($pin, $locked->pin)) {
                $locked->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();

                return 'ok';
            }

            $attempts = $locked->pin_failed_attempts + 1;

            if ($attempts >= (int) config('pos.pin.max_attempts')) {
                $locked->forceFill([
                    'pin_failed_attempts' => 0,
                    'pin_locked_until' => now()->addMinutes((int) config('pos.pin.lockout_minutes')),
                ])->save();

                return 'locked';
            }

            $locked->forceFill(['pin_failed_attempts' => $attempts])->save();

            return 'invalid';
        });

        $user->refresh();

        match ($outcome) {
            'ok' => null,
            'invalid' => throw BusinessException::of(ErrorCode::InvalidPin),
            'locked' => throw BusinessException::of(ErrorCode::PinLocked, details: [
                'retry_after' => max(1, (int) now()->diffInSeconds($user->pin_locked_until, true)),
            ]),
        };
    }
}
