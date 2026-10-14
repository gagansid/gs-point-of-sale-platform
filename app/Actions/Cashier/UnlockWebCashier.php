<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Actions\Auth\PinUsers;
use App\Actions\Auth\VerifyPin;
use App\Actions\Shift\RecordShiftOperator;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Buka kunci layar kasir web (ADR 0017): karyawan yang ditugaskan di outlet perangkat masuk dengan PIN dan
 * menjadi operator shift berjalan — ganti kasir tanpa hitung uang. PIN memakai penghitung & kunci yang sama
 * dengan login PIN aplikasi di perangkat itu (VerifyPin "login:{device}"), jadi tidak membuka jalur brute force
 * baru. Tidak membuat sesi; pemanggil yang login-kan ke guard "cashier".
 */
final class UnlockWebCashier
{
    public function __construct(
        private readonly VerifyPin $verifyPin,
        private readonly LoginWebCashier $login,
        private readonly RecordShiftOperator $operators,
    ) {}

    /** @throws ValidationException */
    public function handle(Device $device, string $userId, string $pin): User
    {
        // Scope tenant aktif: user tenant lain tidak ditemukan → pesan sama dengan PIN salah
        $user = self::users($device)->firstWhere('id', $userId);

        try {
            if ($user === null) {
                throw BusinessException::of(ErrorCode::InvalidPin);
            }

            $this->verifyPin->handle($user, $pin, 'login:'.$device->id, $device->id);
        } catch (BusinessException $e) {
            // INVALID_PIN / PIN_LOCKED / TOO_MANY_REQUESTS → pesan di bawah field, bukan halaman error
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }

        try {
            $this->login->ensureCanEnter($user);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['pin' => $e->validator->errors()->first()]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->operators->handle($device, $user);

        return $user;
    }

    /**
     * Karyawan yang boleh membuka kunci di perangkat ini: ber-PIN, ditugaskan di outlet perangkat, berakses kasir web.
     *
     * @return Collection<int, User>
     */
    public static function users(Device $device): Collection
    {
        return PinUsers::query($device)->with('tenant')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->canUseWebCashier())->values();
    }
}
