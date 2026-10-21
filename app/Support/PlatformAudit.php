<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Bank;
use App\Models\Plan;
use App\Services\Security\Turnstile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat perubahan setelan platform (/admin Sistem → Riwayat perubahan): setiap SystemSettings::set yang mengubah
 * nilai dicatat ke activity log tanpa tenant (log_name "platform"), plus master Bank (LogsChanges). Nilai rahasia
 * (secret Turnstile, server key Midtrans) tidak pernah disimpan: hanya "diisi / diganti / dihapus".
 */
final class PlatformAudit
{
    public const LOG_NAME = 'platform';

    /** Nama setelan untuk tampilan; kunci berawalan (turnstile per halaman) ditangani label(). */
    private const LABELS = [
        SystemSettings::ADMIN_TWO_FACTOR_REQUIRED => 'Wajib 2FA super admin',
        SystemSettings::TURNSTILE_SITE_KEY => 'Turnstile: site key',
        SystemSettings::TURNSTILE_SECRET_KEY => 'Turnstile: secret key',
        SystemSettings::TRIAL_DAYS => 'Lama trial (hari)',
        SystemSettings::SIGNUP_ENABLED => 'Daftar sendiri dibuka',
        SystemSettings::TRIAL_PLAN_ID => 'Paket selama trial',
        SystemSettings::SIGNUP_DAILY_LIMIT => 'Batas pendaftar per hari',
        SystemSettings::SIGNUP_BLOCKED_DOMAINS => 'Domain email diblokir',
        SystemSettings::SIGNUP_NOTIFY_EMAILS => 'Email notifikasi pendaftar',
        SystemSettings::SIGNUP_CLOSED_MESSAGE => 'Pesan pendaftaran ditutup',
        SystemSettings::BILLING_BANK_ACCOUNTS => 'Rekening transfer manual',
        SystemSettings::BILLING_QRIS_IMAGE => 'Gambar QRIS statis',
        SystemSettings::BILLING_MANUAL_NOTE => 'Petunjuk transfer manual',
        SystemSettings::BILLING_MIDTRANS_MODE => 'Mode Midtrans',
    ];

    /** Kelompok menu tempat setelan diubah. */
    public static function group(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'billing.') => 'Pembayaran langganan',
            str_starts_with($key, 'onboarding.') => 'Pendaftaran',
            str_starts_with($key, 'security.') => 'Keamanan',
            default => 'Sistem',
        };
    }

    public static function label(string $key): string
    {
        if (str_starts_with($key, SystemSettings::TURNSTILE_PREFIX)) {
            $audience = substr($key, strlen(SystemSettings::TURNSTILE_PREFIX));

            return 'Turnstile: '.(Turnstile::AUDIENCES[$audience] ?? $audience);
        }
        if (str_starts_with($key, SystemSettings::BILLING_MIDTRANS_KEY_PREFIX)) {
            $mode = substr($key, strlen(SystemSettings::BILLING_MIDTRANS_KEY_PREFIX));

            return 'Server key Midtrans '.(SystemSettings::MIDTRANS_MODES[$mode] ?? $mode);
        }

        return self::LABELS[$key] ?? $key;
    }

    public static function isSecret(string $key): bool
    {
        return $key === SystemSettings::TURNSTILE_SECRET_KEY || str_starts_with($key, SystemSettings::BILLING_MIDTRANS_KEY_PREFIX);
    }

    /** Catat perubahan satu setelan (dipanggil SystemSettings::set). Nilai sama = tidak dicatat. */
    public static function setting(string $key, mixed $old, mixed $new, ?Admin $by): void
    {
        $secret = self::isSecret($key);
        // Array dibandingkan tanpa urutan kunci: kolom JSON MySQL menyusun ulang kunci objek saat disimpan
        $same = is_array($old) && is_array($new) ? $old == $new : $old === $new;
        if (! $secret && $same) {
            return;
        }
        if ($secret && $old === null && $new === null) {
            return;
        }

        $event = match (true) {
            $old === null => 'created',
            $new === null => 'deleted',
            default => 'updated',
        };

        $log = activity(self::LOG_NAME)->event($event)
            ->withProperties([
                'key' => $key,
                'group' => self::group($key),
                // Rahasia tidak pernah disimpan, terenkripsi sekalipun
                'old' => [$key => $secret ? ($old === null ? null : '••••••••') : $old],
                'attributes' => [$key => $secret ? ($new === null ? null : '•••••••• (baru)') : $new],
            ]);
        if ($by !== null) {
            $log->causedBy($by);
        }
        $log->log('setting');
    }

    /** Nilai setelan untuk tabel/detail ("Ya", daftar rekening, dsb.). */
    public static function format(string $key, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            $key === SystemSettings::BILLING_MIDTRANS_MODE => SystemSettings::MIDTRANS_MODES[(string) $value] ?? (string) $value,
            $key === SystemSettings::BILLING_BANK_ACCOUNTS && is_array($value) => implode('; ', array_map(
                fn (mixed $row): string => is_array($row)
                    ? trim(($row['bank'] ?? (filled($row['bank_id'] ?? null) ? Bank::query()->whereKey($row['bank_id'])->value('short_name') : '')).' '.($row['number'] ?? '').' a.n. '.($row['holder'] ?? ''))
                    : (string) json_encode($row),
                $value,
            )),
            $key === SystemSettings::TRIAL_PLAN_ID => (string) (Plan::query()->whereKey($value)->value('name') ?? $value),
            is_array($value) => implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v), $value)),
            default => (string) $value,
        };
    }

    /**
     * Log platform (tanpa tenant) untuk panel admin.
     *
     * @return Builder<ActivityLog>
     */
    public static function query(): Builder
    {
        return ActivityLog::query()->withoutGlobalScopes()->whereNull('tenant_id')
            ->where(fn ($q) => $q->where('log_name', self::LOG_NAME)->orWhere('subject_type', Bank::class));
    }
}
