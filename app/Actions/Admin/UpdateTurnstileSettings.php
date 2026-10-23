<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Services\Security\Turnstile;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pengaturan → Keamanan: kunci Cloudflare Turnstile (site key + secret terenkripsi; cadangan .env) dan on/off per halaman
 * login (super admin, owner & lupa kata sandi, kasir web). Secret baru diuji ke Cloudflare sebelum disimpan.
 * Mengaktifkan butuh kunci; mematikan selalu boleh.
 */
final class UpdateTurnstileSettings
{
    public function __construct(private readonly SystemSettings $settings, private readonly Turnstile $turnstile) {}

    /**
     * @param  array<string, mixed>  $data  halaman login (Turnstile::AUDIENCES) → aktif; site_key / secret_key
     *                                      (kosong = tidak diubah; clear_keys = hapus, kembali ke .env)
     */
    public function handle(Admin $by, array $data): void
    {
        $siteKey = trim((string) ($data['site_key'] ?? ''));
        $secretKey = trim((string) ($data['secret_key'] ?? ''));

        if ($siteKey !== '' && preg_match('/^[0-9A-Za-z_-]{10,100}$/', $siteKey) !== 1) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Site key tidak valid', ['site_key' => ['Site key tidak valid']]);
        }
        if ($secretKey !== '') {
            if (preg_match('/^[0-9A-Za-z_-]{10,100}$/', $secretKey) !== 1) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Secret key tidak valid', ['secret_key' => ['Secret key tidak valid']]);
            }
            // Secret salah = semua login gagal saat Turnstile aktif: cek ke Cloudflare sebelum disimpan
            if ($this->turnstile->secretAccepted($secretKey) === false) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Secret key ditolak Cloudflare', ['secret_key' => ['Secret key ditolak Cloudflare']]);
            }
        }

        $audiences = [];
        foreach (array_keys(Turnstile::AUDIENCES) as $audience) {
            $audiences[$audience] = (bool) ($data[$audience] ?? false);
        }

        DB::transaction(function () use ($by, $data, $siteKey, $secretKey, $audiences): void {
            if ((bool) ($data['clear_keys'] ?? false)) {
                $this->settings->setTurnstileKeys('', '', $by);
            } else {
                $this->settings->setTurnstileKeys($siteKey !== '' ? $siteKey : null, $secretKey !== '' ? $secretKey : null, $by);
            }

            if (in_array(true, $audiences, true) && ! Turnstile::configured()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Isi site key & secret key Turnstile sebelum mengaktifkan');
            }

            $this->settings->setTurnstile($audiences, $by);
        });

        // Secret tidak pernah dicatat
        Log::info('Setelan Turnstile diubah', [...$audiences, 'site_key_changed' => $siteKey !== '', 'secret_changed' => $secretKey !== '', 'admin_id' => $by->id]);
    }
}
