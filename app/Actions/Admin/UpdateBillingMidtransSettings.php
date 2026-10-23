<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Services\Payment\MidtransClient;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\Log;

/**
 * Langganan → Pembayaran langganan → Midtrans: mode aktif (sandbox/dev atau produksi) dan server key per mode, tersimpan
 * terenkripsi (cadangan .env). Key baru dicek formatnya (sandbox `SB-Mid-server-…`, produksi `Mid-server-…`) lalu diuji
 * ke Midtrans sebelum disimpan. Mode tanpa key = pembayaran online nonaktif (owner hanya transfer manual). Key tidak pernah dicatat.
 */
final class UpdateBillingMidtransSettings
{
    public function __construct(private readonly SystemSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data  midtrans_mode, midtrans_sandbox_key, midtrans_production_key (kosong = tidak diubah), midtrans_clear_keys
     * @return bool pembayaran online aktif (key mode terpilih tersedia)
     */
    public function handle(Admin $by, array $data): bool
    {
        $mode = (string) ($data['midtrans_mode'] ?? $this->settings->billingMidtransMode());
        if (! array_key_exists($mode, SystemSettings::MIDTRANS_MODES)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Mode Midtrans tidak valid');
        }

        $clear = (bool) ($data['midtrans_clear_keys'] ?? false);
        $keys = [];
        foreach (array_keys(SystemSettings::MIDTRANS_MODES) as $keyMode) {
            $key = trim((string) ($data['midtrans_'.$keyMode.'_key'] ?? ''));
            if ($key === '') {
                $keys[$keyMode] = $clear ? '' : null;

                continue;
            }

            $field = 'midtrans_'.$keyMode.'_key';
            $pattern = $keyMode === 'sandbox' ? '/^SB-Mid-server-[A-Za-z0-9_-]{8,100}$/' : '/^Mid-server-[A-Za-z0-9_-]{8,100}$/';
            if (preg_match($pattern, $key) !== 1) {
                $message = $keyMode === 'sandbox' ? 'Server key sandbox diawali SB-Mid-server-' : 'Server key produksi diawali Mid-server- (bukan SB-)';
                throw BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
            }

            // Key salah = tagihan tidak bisa dibayar online: uji ke Midtrans sesuai modenya
            try {
                MidtransClient::vendor($keyMode, $key)?->testConnection();
            } catch (BusinessException $e) {
                throw BusinessException::of(ErrorCode::ValidationError, $e->getMessage(), [$field => [$e->getMessage()]]);
            }
            $keys[$keyMode] = $key;
        }

        $this->settings->setBillingMidtrans($mode, $keys, $by);

        Log::info('Setelan Midtrans tagihan diubah', [
            'mode' => $mode,
            'sandbox_key_changed' => ($keys['sandbox'] ?? null) !== null,
            'production_key_changed' => ($keys['production'] ?? null) !== null,
            'admin_id' => $by->id,
        ]);

        return $this->settings->billingMidtransKey($mode) !== null;
    }
}
