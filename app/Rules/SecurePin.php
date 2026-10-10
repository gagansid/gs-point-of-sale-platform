<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PIN kasir 6 digit yang tidak mudah ditebak: bukan angka sama semua (111111) dan bukan
 * deret naik/turun (123456, 654321). PIN dikunci 15 menit setelah 5 kali salah (SPEC).
 */
final class SecurePin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            $fail('PIN harus 6 digit angka.');

            return;
        }

        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn (int $i): int => $digits[$i + 1] - $digits[$i], range(0, 4)));

        // Selisih antar digit selalu 0 (sama semua), +1 (naik), atau -1 (turun)
        if (count($steps) === 1 && in_array($steps[0], [0, 1, -1], true)) {
            $fail('PIN terlalu mudah ditebak. Hindari angka sama atau berurutan.');
        }
    }
}
