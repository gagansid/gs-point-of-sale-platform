<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Device;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Menerbitkan token user: satu token per user per device (login ulang mencabut token lama).
 */
final class IssueUserToken
{
    public function handle(User $user, string $deviceKey, CarbonInterface $expiresAt, ?Device $device = null): NewAccessToken
    {
        return DB::transaction(function () use ($user, $deviceKey, $expiresAt, $device): NewAccessToken {
            $user->tokens()->where('name', $deviceKey)->delete();

            $token = $user->createToken($deviceKey, ['*'], $expiresAt);
            $token->accessToken->forceFill(['device_id' => $device?->id])->save();

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return $token;
        });
    }
}
