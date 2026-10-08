<?php

declare(strict_types=1);

namespace App\Actions\Auth\Data;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Hasil login: user dan token baru (teks token hanya tersedia sekali di sini).
 */
final readonly class AuthResult
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
    ) {}
}
