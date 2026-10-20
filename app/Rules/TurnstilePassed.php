<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Security\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Token Cloudflare Turnstile valid untuk halaman login $audience. Bila Turnstile tidak aktif untuk halaman itu,
 * selalu lolos. Implicit: tetap dijalankan walau field token tidak terkirim (bot tanpa JavaScript).
 */
final class TurnstilePassed implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly string $audience, private readonly ?string $ip = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $turnstile = app(Turnstile::class);

        if (! $turnstile->enabled($this->audience)) {
            return;
        }

        if (! $turnstile->verify(is_string($value) ? $value : null, $this->ip ?? request()->ip())) {
            $fail('Verifikasi keamanan gagal. Centang ulang kotak verifikasi lalu coba lagi.');
        }
    }
}
