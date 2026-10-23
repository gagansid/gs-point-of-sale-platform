<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Admin;
use App\Services\Security\Turnstile;
use App\Support\SystemSettings;

/**
 * Pemeriksaan keamanan konfigurasi untuk Pengaturan → Keamanan (docs/standards/security.md). Hanya membaca config &
 * data; tidak mengubah apa pun. Item `ok = false` menampilkan langkah perbaikan.
 */
final class SecurityChecklist
{
    public function __construct(private readonly SystemSettings $settings) {}

    /**
     * @return list<array{key: string, label: string, ok: bool, value: string, fix: string}>
     */
    public function items(): array
    {
        $production = app()->environment('production');
        $admins = Admin::query()->get(['id', 'app_authentication_secret']);
        $withTwoFactor = $admins->filter(fn (Admin $a): bool => $a->hasTwoFactorEnabled())->count();
        $lifetime = (int) config('session.lifetime');
        $https = str_starts_with((string) config('app.url'), 'https://');

        return [
            [
                'key' => 'debug', 'label' => 'Mode debug mati',
                'ok' => ! (bool) config('app.debug'),
                'value' => config('app.debug') ? 'APP_DEBUG=true' : 'APP_DEBUG=false',
                'fix' => 'Set APP_DEBUG=false di .env agar pesan error tidak membocorkan kode & konfigurasi',
            ],
            [
                'key' => 'env', 'label' => 'Lingkungan production',
                'ok' => $production,
                'value' => 'APP_ENV='.app()->environment(),
                'fix' => 'Set APP_ENV=production di server produksi',
            ],
            [
                'key' => 'https', 'label' => 'HTTPS',
                'ok' => $https,
                'value' => (string) config('app.url'),
                'fix' => 'Pasang SSL (AutoSSL cPanel) dan ubah APP_URL ke https://',
            ],
            [
                'key' => 'secure_cookie', 'label' => 'Cookie sesi hanya lewat HTTPS',
                'ok' => (bool) config('session.secure'),
                'value' => config('session.secure') ? 'Aktif' : 'Tidak aktif',
                'fix' => 'Set SESSION_SECURE_COOKIE=true setelah HTTPS aktif',
            ],
            [
                'key' => 'session_lifetime', 'label' => 'Sesi berakhir otomatis ≤ 2 jam',
                'ok' => $lifetime > 0 && $lifetime <= 120,
                'value' => $lifetime.' menit',
                'fix' => 'Set SESSION_LIFETIME=120 (menit) atau kurang',
            ],
            [
                'key' => 'two_factor_required', 'label' => 'Wajib 2FA untuk super admin',
                'ok' => $this->settings->adminTwoFactorRequired(),
                'value' => $this->settings->adminTwoFactorRequired() ? 'Wajib' : 'Tidak wajib',
                'fix' => 'Klik "Wajibkan 2FA" di kanan atas halaman ini',
            ],
            [
                'key' => 'turnstile_admin', 'label' => 'Turnstile di login super admin',
                'ok' => app(Turnstile::class)->enabled(Turnstile::ADMIN),
                'value' => app(Turnstile::class)->enabled(Turnstile::ADMIN) ? 'Aktif' : (Turnstile::configured() ? 'Nonaktif' : 'Kunci belum diisi'),
                'fix' => 'Isi kunci & aktifkan di kartu Cloudflare Turnstile di bawah (tombol Atur)',
            ],
            [
                'key' => 'two_factor_all', 'label' => 'Semua super admin memakai 2FA',
                'ok' => $admins->isNotEmpty() && $withTwoFactor === $admins->count(),
                'value' => $withTwoFactor.' dari '.$admins->count().' admin',
                'fix' => 'Minta admin tanpa 2FA mengaktifkannya di halaman profil',
            ],
        ];
    }

    /** Jumlah item lolos, untuk ringkasan "5 dari 7 lolos". */
    public function passed(): int
    {
        return count(array_filter($this->items(), fn (array $i): bool => $i['ok']));
    }
}
