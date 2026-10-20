<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\SystemSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile di halaman login (anti-bot / credential stuffing). Aktif per halaman login (super admin,
 * owner + daftar + lupa kata sandi, kasir web) lewat Sistem → Keamanan, dan hanya bila kunci TURNSTILE_* terisi.
 * Verifikasi fail-closed: Cloudflare tidak bisa dihubungi = ditolak (pengguna diminta mencoba lagi).
 */
final class Turnstile
{
    public const ADMIN = 'admin';

    public const OWNER = 'owner';

    public const CASHIER = 'cashier';

    /** @var array<string, string> halaman login → label setelan */
    public const AUDIENCES = [
        self::ADMIN => 'Login super admin',
        self::OWNER => 'Login owner, daftar & lupa kata sandi',
        self::CASHIER => 'Login kasir web',
    ];

    /** Nama field token yang dikirim widget Cloudflare. */
    public const FIELD = 'cf-turnstile-response';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly SystemSettings $settings) {}

    /** Kunci diisi di Sistem → Keamanan, atau .env (TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY) sebagai cadangan. */
    public static function configured(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function siteKey(): string
    {
        return app(SystemSettings::class)->turnstileSiteKey() ?? (string) config('services.turnstile.site_key');
    }

    public static function secretKey(): string
    {
        return app(SystemSettings::class)->turnstileSecretKey() ?? (string) config('services.turnstile.secret_key');
    }

    /** Asal kunci untuk ditampilkan admin: "admin" (diisi di panel), "env", atau null. */
    public static function keySource(): ?string
    {
        $settings = app(SystemSettings::class);

        return match (true) {
            $settings->turnstileSiteKey() !== null && $settings->turnstileSecretKey() !== null => 'admin',
            filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')) => 'env',
            default => null,
        };
    }

    /**
     * Secret dikenali Cloudflare? Dicek dengan token kosong: secret salah → `invalid-input-secret`, secret benar →
     * hanya `invalid-input-response`. null = Cloudflare tidak bisa dihubungi.
     */
    public function secretAccepted(string $secret): ?bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, ['secret' => $secret, 'response' => 'gspos-key-check']);
        } catch (Throwable) {
            return null;
        }

        return ! in_array('invalid-input-secret', (array) $response->json('error-codes', []), true);
    }

    public function enabled(string $audience): bool
    {
        return self::configured() && $this->settings->turnstileEnabled($audience);
    }

    /** Token dari widget valid (sekali pakai) untuk IP ini. */
    public function verify(?string $token, ?string $ip): bool
    {
        if (blank($token) || strlen((string) $token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => self::secretKey(),
                'response' => (string) $token,
                'remoteip' => $ip,
            ]));

            return $response->ok() && $response->json('success') === true;
        } catch (Throwable $e) {
            Log::warning('Turnstile tidak bisa diverifikasi', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
