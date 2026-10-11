<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Services\Member\MemberProof;
use Illuminate\Support\Facades\Hash;

/**
 * Bukti pemilik saat menukar poin (ADR 0013): kode QR dinamis dari halaman member, atau PIN member 4 digit.
 * PIN salah 5 kali → dikunci 15 menit. Dipanggil DI LUAR transaksi agar percobaan salah tetap tercatat.
 */
final class VerifyMemberProof
{
    public const MAX_PIN_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /** @throws BusinessException INVALID_MEMBER_PROOF, MEMBER_PIN_LOCKED */
    public function handle(Member $member, ?string $code, ?string $pin): void
    {
        if ($code !== null) {
            if (! MemberProof::verifyAndConsume($member, $code)) {
                throw BusinessException::of(ErrorCode::InvalidMemberProof, 'Kode QR member salah atau sudah kedaluwarsa');
            }

            return;
        }

        if ($pin === null) {
            throw BusinessException::of(ErrorCode::InvalidMemberProof, 'Menukar poin butuh kode QR atau PIN member');
        }

        $member->refresh();

        if ($member->pin_locked_until !== null && $member->pin_locked_until->isFuture()) {
            throw BusinessException::of(ErrorCode::MemberPinLocked, details: ['retry_after' => (int) now()->diffInSeconds($member->pin_locked_until, true)]);
        }

        if ($member->getAttribute('pin') !== null && Hash::check($pin, (string) $member->getAttribute('pin'))) {
            $member->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->saveQuietly();

            return;
        }

        $attempts = $member->pin_failed_attempts + 1;
        $locked = $attempts >= self::MAX_PIN_ATTEMPTS;
        $member->forceFill([
            'pin_failed_attempts' => $locked ? 0 : $attempts,
            'pin_locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null,
        ])->saveQuietly();

        throw $locked
            ? BusinessException::of(ErrorCode::MemberPinLocked, details: ['retry_after' => self::LOCK_MINUTES * 60])
            : BusinessException::of(ErrorCode::InvalidMemberProof, $member->getAttribute('pin') === null ? 'Member belum punya PIN. Pakai kode QR halaman member' : 'PIN member salah');
    }
}
