<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Member;
use App\Models\Tenant;
use App\Support\DummyPasswordHash;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Masuk member dengan nomor HP + kata sandi (ADR 0013, Q67) — dipakai halaman member & halaman pesan meja (ADR 0027).
 * Pesan umum & waktu konstan (hash dummy); batas 5/menit per HP+IP dan 20/menit per IP; 10 salah dalam 15 menit = kunci.
 * Dipanggil di dalam tenant context bisnis itu.
 */
final class AuthenticateMember
{
    private const PER_PHONE = 5;

    private const PER_IP = 20;

    private const LOCK_AFTER = 10;

    private const LOCK_SECONDS = 900;

    public const INVALID = 'Nomor HP atau kata sandi salah';

    /** @throws ValidationException field `phone` */
    public function handle(Tenant $tenant, string $phoneInput, string $password, string $ip): Member
    {
        $phone = Phone::normalize($phoneInput) ?? mb_strtolower(trim($phoneInput));
        $keys = [
            'member-login:'.$tenant->id.'|'.$phone.'|'.$ip => self::PER_PHONE,
            'member-login-ip:'.$ip => self::PER_IP,
        ];
        $lockKey = 'member-login-lock:'.$tenant->id.'|'.$phone;

        foreach ([...$keys, $lockKey => self::LOCK_AFTER] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['phone' => 'Terlalu banyak percobaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.']);
            }
        }

        // Hash dummy di semua jalur: waktu respons sama untuk nomor terdaftar maupun tidak
        $member = Member::query()->where('phone', $phone)->first();
        $hash = $member?->getAttribute('password');
        $valid = Hash::check($password, is_string($hash) ? $hash : DummyPasswordHash::get());

        if ($member === null || ! is_string($hash) || ! $valid || ! $member->isUsable()) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, 60);
            }
            RateLimiter::hit($lockKey, self::LOCK_SECONDS);

            throw ValidationException::withMessages(['phone' => self::INVALID]);
        }

        RateLimiter::clear(array_key_first($keys));
        RateLimiter::clear($lockKey);

        return $member;
    }
}
