<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Menghapus token yang sedang dipakai (POST /auth/logout).
 */
final class Logout
{
    public function handle(?HasAbilities $token): void
    {
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
