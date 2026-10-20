<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PaymentGatewaySetting;
use App\Support\SystemSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien HTTP Midtrans (Snap + Core status/cancel) memakai key owner (ADR 0014, Q72). Gagal jaringan/ditolak Midtrans
 * → PAYMENT_GATEWAY_ERROR (502); key tidak pernah ditulis ke log.
 */
final class MidtransClient
{
    public function __construct(private readonly PaymentGatewaySetting $setting) {}

    public static function for(PaymentGatewaySetting $setting): self
    {
        return new self($setting);
    }

    /**
     * Akun Midtrans milik vendor gs.POS untuk tagihan langganan (ADR 0024): mode aktif (sandbox/produksi) & key-nya dari
     * SystemSettings (panel → .env). null bila key mode aktif belum diisi. $mode/$key untuk menguji key baru.
     */
    public static function vendor(?string $mode = null, ?string $key = null): ?self
    {
        $settings = app(SystemSettings::class);
        $mode ??= $settings->billingMidtransMode();
        $key ??= $settings->billingMidtransKey($mode);
        if (blank($key)) {
            return null;
        }

        $setting = new PaymentGatewaySetting;
        $setting->forceFill(['server_key' => (string) $key, 'is_production' => $mode === 'production']);

        return new self($setting);
    }

    /** Server key vendor mode aktif (tanda tangan webhook tagihan). */
    public static function vendorServerKey(): ?string
    {
        return app(SystemSettings::class)->billingMidtransKey();
    }

    /**
     * Buat transaksi Snap; nominal bulat rupiah (IDR tanpa desimal).
     *
     * @param  array{first_name: string, phone?: string|null}  $customer
     * @param  list<string>|null  $channels  kanal Snap; null = setelan akun (enabled_payments)
     * @return array{token: string, redirect_url: string}
     *
     * @throws BusinessException
     */
    public function createSnap(string $orderId, int $amount, array $customer, string $finishUrl, ?array $channels = null): array
    {
        $response = $this->send(fn () => $this->request()->post($this->snapUrl().'/snap/v1/transactions', array_filter([
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
            'customer_details' => array_filter($customer),
            'enabled_payments' => $channels ?: ($this->setting->enabled_payments ?: null),
            'callbacks' => ['finish' => $finishUrl],
            'expiry' => ['unit' => 'minutes', 'duration' => 30],
        ])));

        $token = $response->json('token');
        $url = $response->json('redirect_url');

        if (! is_string($token) || ! is_string($url)) {
            throw BusinessException::of(ErrorCode::PaymentGatewayError, 'Respons Midtrans tidak lengkap');
        }

        return ['token' => $token, 'redirect_url' => $url];
    }

    /**
     * Status transaksi langsung dari API Midtrans (sumber kebenaran, bukan isi webhook).
     *
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function status(string $orderId): array
    {
        return (array) $this->send(fn () => $this->request()->get($this->apiUrl().'/v2/'.rawurlencode($orderId).'/status'), allow404: false)->json();
    }

    /** Batalkan transaksi yang belum dibayar (order kedaluwarsa); gagal diabaikan (Snap juga kedaluwarsa sendiri). */
    public function cancel(string $orderId): void
    {
        try {
            $this->request()->post($this->apiUrl().'/v2/'.rawurlencode($orderId).'/cancel');
        } catch (ConnectionException) {
            // abaikan
        }
    }

    /**
     * Tes koneksi: status order acak. Key salah → 401; key benar → 404 (transaksi tidak ada).
     *
     * @throws BusinessException
     */
    public function testConnection(): void
    {
        try {
            $response = $this->request()->get($this->apiUrl().'/v2/gspos-test-'.bin2hex(random_bytes(4)).'/status');
        } catch (ConnectionException) {
            throw BusinessException::of(ErrorCode::PaymentGatewayError, 'Tidak bisa terhubung ke Midtrans');
        }

        $code = (string) ($response->json('status_code') ?? $response->status());

        if ($response->status() === 401 || $code === '401') {
            throw BusinessException::of(ErrorCode::PaymentGatewayError, 'Server Key ditolak Midtrans. Periksa key & mode (sandbox/production)');
        }
    }

    /** Tanda tangan notifikasi Midtrans: SHA512(order_id + status_code + gross_amount + server_key). */
    public static function signature(string $orderId, string $statusCode, string $grossAmount, string $serverKey): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->setting->server_key, '')
            ->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws BusinessException
     */
    private function send(callable $call, bool $allow404 = false): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw BusinessException::of(ErrorCode::PaymentGatewayError, 'Tidak bisa terhubung ke Midtrans. Coba lagi');
        }

        if ($response->failed() && ! ($allow404 && $response->status() === 404)) {
            Log::warning('Midtrans menolak permintaan', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            throw BusinessException::of(ErrorCode::PaymentGatewayError, 'Midtrans menolak permintaan. Coba lagi atau bayar di kasir');
        }

        return $response;
    }

    private function snapUrl(): string
    {
        return $this->setting->is_production ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiUrl(): string
    {
        return $this->setting->is_production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }
}
