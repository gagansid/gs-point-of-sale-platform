<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Batas percobaan kode voucher di layar web (GSPOS-SEC-027), agar kode promo pendek buatan owner tidak bisa ditebak
 * beruntun. Setiap kode baru yang dicek dihitung, sah atau tidak. API `/vouchers/check` memakai batas sendiri.
 *
 *   pesan dari meja (publik)  per IP 10/menit + per meja 30/menit (banyak HP di satu meja)
 *   kasir web                 per user 30/menit (sama dengan API)
 */
final class VoucherAttempts
{
    public const PUBLIC_PER_IP_PER_MINUTE = 10;

    public const PUBLIC_PER_TABLE_PER_MINUTE = 30;

    public const STAFF_PER_MINUTE = 30;

    /** Pesan gagal untuk pelanggan publik: sama untuk semua alasan agar kode yang ada tidak bisa dibedakan. */
    public const PUBLIC_INVALID_MESSAGE = 'Kode voucher tidak bisa dipakai untuk pesanan ini. Cek kode, masa berlaku, dan minimal belanjanya';

    /** @throws BusinessException TOO_MANY_REQUESTS */
    public static function hitPublic(string $ip, string $tableId): void
    {
        self::hit([
            'voucher-try:ip:'.$ip => self::PUBLIC_PER_IP_PER_MINUTE,
            'voucher-try:table:'.$tableId => self::PUBLIC_PER_TABLE_PER_MINUTE,
        ]);
    }

    /** @throws BusinessException TOO_MANY_REQUESTS */
    public static function hitStaff(string $userId): void
    {
        self::hit(['voucher-try:user:'.$userId => self::STAFF_PER_MINUTE]);
    }

    /**
     * Semua batas dicek dulu sebelum dihitung, sehingga percobaan yang ditolak tidak memperpanjang hitungan.
     *
     * @param  array<string, int>  $limits  kunci → maks. percobaan per menit
     *
     * @throws BusinessException TOO_MANY_REQUESTS
     */
    private static function hit(array $limits): void
    {
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw BusinessException::of(ErrorCode::TooManyRequests,
                    'Terlalu banyak mencoba kode voucher. Coba lagi dalam '.RateLimiter::availableIn($key).' detik');
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 60);
        }
    }
}
