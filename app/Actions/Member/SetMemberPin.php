<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * PIN member 4 digit (ADR 0013): cadangan bukti pemilik saat menukar poin bila kode QR tidak bisa ditunjukkan.
 * Bukan angka sama semua / berurutan. Mengganti PIN membuka kunci percobaan salah.
 */
final class SetMemberPin
{
    /** @throws BusinessException VALIDATION_ERROR */
    public function handle(Member $member, string $pin): Member
    {
        if (preg_match('/^\d{4}$/', $pin) !== 1 || self::weak($pin)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'PIN tidak valid', ['pin' => ['PIN 4 digit angka, bukan angka sama/berurutan']]);
        }

        DB::transaction(fn () => $member->forceFill(['pin' => $pin, 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save());

        return $member;
    }

    private static function weak(string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn (int $i): int => $digits[$i + 1] - $digits[$i], range(0, 2)));

        return count($steps) === 1 && in_array($steps[0], [0, 1, -1], true);
    }
}
