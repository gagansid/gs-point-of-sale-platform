<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Kode QR dinamis halaman member (ADR 0013): 6 digit dari HMAC(app key, member + jendela 30 detik). Kasir memindai
 * (atau mengetik) kode sebagai bukti pemilik saat menukar poin. Berlaku 2 menit (4 jendela) dan sekali pakai.
 */
final class MemberProof
{
    public const WINDOW_SECONDS = 30;

    /** Jendela yang diterima (sekarang + 3 sebelumnya ≈ 2 menit) */
    private const ACCEPTED_WINDOWS = 4;

    public static function current(Member $member, ?CarbonImmutable $at = null): string
    {
        return self::codeFor($member, self::window($at ?? CarbonImmutable::now()));
    }

    /** Sisa detik sebelum kode berganti (tampilan halaman member). */
    public static function secondsLeft(?CarbonImmutable $at = null): int
    {
        $ts = ($at ?? CarbonImmutable::now())->getTimestamp();

        return self::WINDOW_SECONDS - ($ts % self::WINDOW_SECONDS);
    }

    /** Benar & belum dipakai → ditandai terpakai. */
    public static function verifyAndConsume(Member $member, string $code, ?CarbonImmutable $at = null): bool
    {
        $window = self::window($at ?? CarbonImmutable::now());

        for ($i = 0; $i < self::ACCEPTED_WINDOWS; $i++) {
            if (hash_equals(self::codeFor($member, $window - $i), $code)) {
                // add() atomik: kode yang sama tidak bisa dipakai dua kali
                return Cache::add('member-proof:'.$member->id.':'.$code, true, self::WINDOW_SECONDS * (self::ACCEPTED_WINDOWS + 1));
            }
        }

        return false;
    }

    private static function window(CarbonImmutable $at): int
    {
        return intdiv($at->getTimestamp(), self::WINDOW_SECONDS);
    }

    private static function codeFor(Member $member, int $window): string
    {
        $hash = hash_hmac('sha256', $member->tenant_id.'|'.$member->id.'|'.$window, (string) config('app.key'));

        return str_pad((string) (hexdec(substr($hash, 0, 8)) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
