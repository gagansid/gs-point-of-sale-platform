<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Actions\Auth\VerifyPin;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Masuk ulang ke kasir web dari menu Kasir di dashboard (Q55): identitas = user sesi /dashboard,
 * dibuktikan lagi dengan kata sandi atau PIN. PIN memakai penghitung & kunci yang sama dengan approval
 * (VerifyPin), jadi tidak membuka jalur brute force baru. Tidak membuat sesi; pemanggil yang login-kan
 * ke guard "cashier".
 */
final class ConfirmWebCashierUser
{
    public const METHOD_PASSWORD = 'password';

    public const METHOD_PIN = 'pin';

    public function __construct(
        private readonly VerifyPin $verifyPin,
        private readonly LoginWebCashier $login,
    ) {}

    /**
     * @param  string  $source  sumber percobaan PIN (mis. IP) untuk batas per sumber
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $method, string $secret, string $source): User
    {
        if ($method === self::METHOD_PIN) {
            $this->checkPin($user, $secret, $source);
        } elseif (! Hash::check($secret, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi salah.']);
        }

        $this->login->ensureCanEnter($user);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $user;
    }

    /** @throws ValidationException */
    private function checkPin(User $user, string $pin, string $source): void
    {
        if ($user->pin === null) {
            throw ValidationException::withMessages(['pin' => 'PIN belum diatur. Masuk dengan kata sandi.']);
        }

        try {
            $this->verifyPin->handle($user, $pin, 'web-cashier-login:'.$source);
        } catch (BusinessException $e) {
            // INVALID_PIN / PIN_LOCKED / TOO_MANY_REQUESTS → pesan di bawah field, bukan halaman error
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }
    }
}
