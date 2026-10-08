<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Auth\Data\AuthResult;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Login owner/manager di aplikasi dengan email + kata sandi (POST /auth/login).
 */
final class LoginWithPassword
{
    public function __construct(private readonly IssueUserToken $issueToken) {}

    /**
     * @throws BusinessException
     */
    public function handle(string $email, string $password, string $deviceUid): AuthResult
    {
        // Lintas tenant: tenant baru diketahui setelah user ditemukan
        $user = User::allTenants()->with('tenant')->where('email', mb_strtolower($email))->first();

        // Hash tetap dicek walau user tidak ada agar waktu respons sama (anti enumerasi akun)
        $passwordValid = Hash::check($password, $user->password ?? $this->dummyHash());

        if ($user === null || ! $passwordValid || ! $user->is_active) {
            throw BusinessException::of(ErrorCode::Unauthenticated, 'Email atau kata sandi salah');
        }

        if (! $user->role->canUsePasswordLogin()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Gunakan login PIN di perangkat kasir');
        }

        if (! $user->tenant->isActive()) {
            throw BusinessException::of(ErrorCode::TenantSuspended);
        }

        $expiresAt = now()->addMinutes((int) config('pos.tokens.owner_ttl'));
        $token = $this->issueToken->handle($user, 'app:'.$deviceUid, $expiresAt);

        return new AuthResult($user, $token);
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('dummy-password-untuk-waktu-konstan');
    }
}
