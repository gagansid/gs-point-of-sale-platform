<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Atur kata sandi baru dari link email: lupa kata sandi (broker "users", 60 menit) atau undangan
 * owner (broker "invitations", 3 hari). Link membuktikan kepemilikan email → email terverifikasi.
 * Semua sesi/token lama diputus.
 */
final class ResetPasswordWithToken
{
    /**
     * @return string status broker (Password::PASSWORD_RESET bila berhasil)
     */
    public function handle(string $email, string $token, string $password, bool $invitation): string
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker($invitation ? 'invitations' : 'users');

        return $broker->reset(
            ['email' => mb_strtolower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->password = $password;
                    $user->forceFill([
                        'remember_token' => Str::random(60),
                        'email_verified_at' => $user->email_verified_at ?? now(),
                    ])->saveQuietly();

                    $user->tokens()->delete();
                });
            },
        );
    }
}
