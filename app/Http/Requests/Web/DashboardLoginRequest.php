<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Rules\TurnstilePassed;
use App\Services\Security\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

final class DashboardLoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
            'next' => ['nullable', 'string', 'max:500'],
            // Cloudflare Turnstile bila diaktifkan admin untuk login owner (Sistem → Keamanan)
            Turnstile::FIELD => [new TurnstilePassed(Turnstile::OWNER)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['email' => 'alamat email', 'password' => 'kata sandi'];
    }
}
