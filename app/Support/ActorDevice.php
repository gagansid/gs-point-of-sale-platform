<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * Perangkat kasir yang sedang dipakai user: device token API (Flutter) atau device browser kasir web (Q54).
 * Null di dashboard / token tanpa device.
 */
final class ActorDevice
{
    public static function id(User $user): ?string
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->device_id;
        }

        return WebCashier::deviceOrNull()?->id;
    }
}
