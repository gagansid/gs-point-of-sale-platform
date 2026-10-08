<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Auth\Data\AuthResult;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\User;

/**
 * Login kasir dengan PIN di device terdaftar (POST /auth/pin-login).
 * Device sudah divalidasi middleware; tenant context = tenant device.
 */
final class LoginWithPin
{
    public function __construct(
        private readonly VerifyPin $verifyPin,
        private readonly IssueUserToken $issueToken,
    ) {}

    /**
     * @throws BusinessException
     */
    public function handle(Device $device, string $userId, string $pin): AuthResult
    {
        // Scope tenant aktif: user tenant lain otomatis tidak ditemukan
        $user = PinUsers::query($device)->find($userId);

        if ($user === null) {
            // Pesan sama dengan PIN salah agar ID karyawan tidak bisa ditebak
            throw BusinessException::of(ErrorCode::InvalidPin);
        }

        $this->verifyPin->handle($user, $pin);

        $expiresAt = now()->addMinutes((int) config('pos.tokens.cashier_ttl'));
        $token = $this->issueToken->handle($user, 'pin:'.$device->device_uid, $expiresAt, $device);

        return new AuthResult($user, $token);
    }
}
