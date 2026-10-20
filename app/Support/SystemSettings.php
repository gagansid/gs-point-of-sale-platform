<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Akses bertipe ke setelan sistem global. Nilai di-cache (driver database) dan cache
 * dihapus setiap kali nilai diubah, sehingga perubahan langsung berlaku.
 */
final class SystemSettings
{
    public const ADMIN_TWO_FACTOR_REQUIRED = 'security.admin_two_factor_required';

    /** Prefix on/off Cloudflare Turnstile per halaman login (Turnstile::AUDIENCES). */
    public const TURNSTILE_PREFIX = 'security.turnstile.';

    public const TURNSTILE_SITE_KEY = 'security.turnstile_site_key';

    /** Secret Turnstile disimpan terenkripsi (Crypt, APP_KEY); tidak pernah dikirim ke browser. */
    public const TURNSTILE_SECRET_KEY = 'security.turnstile_secret_key';

    public const TRIAL_DAYS = 'onboarding.trial_days';

    public const SIGNUP_ENABLED = 'onboarding.signup_enabled';

    public const TRIAL_PLAN_ID = 'onboarding.trial_plan_id';

    public const SIGNUP_DAILY_LIMIT = 'onboarding.signup_daily_limit';

    public const SIGNUP_BLOCKED_DOMAINS = 'onboarding.signup_blocked_domains';

    public const SIGNUP_NOTIFY_EMAILS = 'onboarding.signup_notify_emails';

    public const SIGNUP_CLOSED_MESSAGE = 'onboarding.signup_closed_message';

    /** Batas pendaftaran mandiri per hari (0 = tanpa batas). */
    public const SIGNUP_DAILY_LIMIT_MAX = 10000;

    /** Batas lama trial yang bisa diatur admin (hari). */
    public const TRIAL_DAYS_MIN = 1;

    public const TRIAL_DAYS_MAX = 90;

    /** Rekening tujuan transfer manual tagihan langganan (Q123): list {bank_id, bank, number, holder}. */
    public const BILLING_BANK_ACCOUNTS = 'billing.bank_accounts';

    /** Gambar QRIS statis vendor (disk public) untuk transfer manual. */
    public const BILLING_QRIS_IMAGE = 'billing.qris_image';

    /** Petunjuk tambahan transfer manual. */
    public const BILLING_MANUAL_NOTE = 'billing.manual_note';

    public const BILLING_ACCOUNTS_MAX = 5;

    private const CACHE_PREFIX = 'system_settings:';

    /**
     * Wajibkan 2FA untuk semua super admin. Default dari config (aman: aktif) bila belum pernah diatur.
     */
    public function adminTwoFactorRequired(): bool
    {
        return (bool) $this->get(self::ADMIN_TWO_FACTOR_REQUIRED, config('pos.security.admin_two_factor_required'));
    }

    public function setAdminTwoFactorRequired(bool $required, ?Admin $by = null): void
    {
        $this->set(self::ADMIN_TWO_FACTOR_REQUIRED, $required, $by);
    }

    /** Turnstile aktif untuk halaman login ini (bawaan mati sampai admin mengaktifkan). */
    public function turnstileEnabled(string $audience): bool
    {
        return (bool) $this->get(self::TURNSTILE_PREFIX.$audience, false);
    }

    public function turnstileSiteKey(): ?string
    {
        $key = $this->get(self::TURNSTILE_SITE_KEY, null);

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function turnstileSecretKey(): ?string
    {
        $encrypted = $this->get(self::TURNSTILE_SECRET_KEY, null);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // APP_KEY berganti: secret lama tidak terbaca, dianggap belum diisi
            return null;
        }
    }

    /** null = tidak diubah, '' = hapus (kembali ke .env). */
    public function setTurnstileKeys(?string $siteKey, ?string $secretKey, ?Admin $by = null): void
    {
        if ($siteKey !== null) {
            $this->set(self::TURNSTILE_SITE_KEY, $siteKey !== '' ? $siteKey : null, $by);
        }
        if ($secretKey !== null) {
            $this->set(self::TURNSTILE_SECRET_KEY, $secretKey !== '' ? Crypt::encryptString($secretKey) : null, $by);
        }
    }

    /** @param array<string, bool> $audiences halaman login → aktif */
    public function setTurnstile(array $audiences, ?Admin $by = null): void
    {
        foreach ($audiences as $audience => $enabled) {
            $this->set(self::TURNSTILE_PREFIX.$audience, $enabled, $by);
        }
    }

    /** Lama trial tenant baru (ADR 0009, default 14 hari). */
    public function trialDays(): int
    {
        $days = (int) $this->get(self::TRIAL_DAYS, config('pos.onboarding.trial_days'));

        return max(self::TRIAL_DAYS_MIN, min(self::TRIAL_DAYS_MAX, $days));
    }

    /** Daftar sendiri di gspos.id/register dibuka. */
    public function signupEnabled(): bool
    {
        // Jual putus: tidak ada daftar mandiri (ADR 0009)
        return Edition::isSaas() && (bool) $this->get(self::SIGNUP_ENABLED, config('pos.onboarding.signup_enabled'));
    }

    public function setOnboarding(int $trialDays, bool $signupEnabled, ?Admin $by = null): void
    {
        $this->set(self::TRIAL_DAYS, $trialDays, $by);
        $this->set(self::SIGNUP_ENABLED, $signupEnabled, $by);
    }

    /** Paket yang dipakai selama trial (fitur & kuota paket); null = semua fitur tanpa batas. */
    public function trialPlanId(): ?string
    {
        $id = $this->get(self::TRIAL_PLAN_ID, null);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Maksimal pendaftaran mandiri per hari (zona waktu platform); 0 = tanpa batas. */
    public function signupDailyLimit(): int
    {
        return max(0, min(self::SIGNUP_DAILY_LIMIT_MAX, (int) $this->get(self::SIGNUP_DAILY_LIMIT, 0)));
    }

    /** @return list<string> domain email yang ditolak saat daftar mandiri (huruf kecil, mis. mailinator.com) */
    public function signupBlockedDomains(): array
    {
        return array_values(array_filter(array_map('strval', (array) $this->get(self::SIGNUP_BLOCKED_DOMAINS, []))));
    }

    /** @return list<string> email tim yang diberi tahu setiap ada pendaftar baru */
    public function signupNotifyEmails(): array
    {
        return array_values(array_filter(array_map('strval', (array) $this->get(self::SIGNUP_NOTIFY_EMAILS, []))));
    }

    /** Pesan di halaman daftar saat pendaftaran ditutup; null = pesan bawaan. */
    public function signupClosedMessage(): ?string
    {
        $message = $this->get(self::SIGNUP_CLOSED_MESSAGE, null);

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    /**
     * Setelan lanjutan pendaftaran (Sistem → Pendaftaran).
     *
     * @param  list<string>  $blockedDomains
     * @param  list<string>  $notifyEmails
     */
    public function setOnboardingExtras(?string $trialPlanId, int $dailyLimit, array $blockedDomains, array $notifyEmails, ?string $closedMessage, ?Admin $by = null): void
    {
        $this->set(self::TRIAL_PLAN_ID, $trialPlanId, $by);
        $this->set(self::SIGNUP_DAILY_LIMIT, $dailyLimit, $by);
        $this->set(self::SIGNUP_BLOCKED_DOMAINS, $blockedDomains, $by);
        $this->set(self::SIGNUP_NOTIFY_EMAILS, $notifyEmails, $by);
        $this->set(self::SIGNUP_CLOSED_MESSAGE, $closedMessage, $by);
    }

    /**
     * Rekening gs.POS untuk transfer manual (bank dari master Sistem → Bank; nama bank tersimpan sebagai cadangan).
     *
     * @return list<array{bank_id: string|null, bank: string, number: string, holder: string}>
     */
    public function billingBankAccounts(): array
    {
        $accounts = [];
        foreach ((array) $this->get(self::BILLING_BANK_ACCOUNTS, []) as $row) {
            if (is_array($row) && (filled($row['bank_id'] ?? null) || filled($row['bank'] ?? null)) && filled($row['number'] ?? null)) {
                $accounts[] = [
                    'bank_id' => filled($row['bank_id'] ?? null) ? (string) $row['bank_id'] : null,
                    'bank' => (string) ($row['bank'] ?? ''),
                    'number' => (string) $row['number'],
                    'holder' => (string) ($row['holder'] ?? ''),
                ];
            }
        }

        return $accounts;
    }

    public function billingQrisImage(): ?string
    {
        $path = $this->get(self::BILLING_QRIS_IMAGE, null);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** Petunjuk transfer manual; bawaan dari POS_BILLING_BANK_INFO. */
    public function billingManualNote(): ?string
    {
        $note = $this->get(self::BILLING_MANUAL_NOTE, null);
        if (is_string($note) && trim($note) !== '') {
            return $note;
        }

        $fallback = config('pos.billing.bank_info');

        return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    /** Transfer manual tersedia: ada rekening atau QRIS statis. */
    public function manualBillingAvailable(): bool
    {
        return $this->billingBankAccounts() !== [] || $this->billingQrisImage() !== null;
    }

    /** @param  list<array{bank_id: string|null, bank: string, number: string, holder: string}>  $accounts */
    public function setBillingPayment(array $accounts, ?string $qrisImage, ?string $note, ?Admin $by = null): void
    {
        $this->set(self::BILLING_BANK_ACCOUNTS, $accounts !== [] ? $accounts : null, $by);
        $this->set(self::BILLING_QRIS_IMAGE, $qrisImage, $by);
        $this->set(self::BILLING_MANUAL_NOTE, $note, $by);
    }

    private function get(string $key, mixed $default): mixed
    {
        $stored = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key): array {
            $setting = SystemSetting::query()->find($key);

            // Dibungkus array agar "belum diatur" bisa dibedakan dari nilai false/null
            return $setting === null ? [] : ['value' => $setting->value];
        });

        return array_key_exists('value', $stored) ? $stored['value'] : $default;
    }

    private function set(string $key, mixed $value, ?Admin $by): void
    {
        // Kolom value NOT NULL: null = setelan dihapus (kembali ke nilai bawaan)
        if ($value === null) {
            SystemSetting::query()->whereKey($key)->delete();
        } else {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        }

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
