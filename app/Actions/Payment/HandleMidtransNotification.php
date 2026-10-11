<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\GatewayTransaction;
use App\Models\PaymentGatewaySetting;
use App\Services\Payment\MidtransClient;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi (webhook) Midtrans untuk satu tenant (ADR 0014, Q72). Urutan pemeriksaan: tanda tangan SHA512 dengan
 * Server Key owner → transaksi milik tenant ini → status DICEK ULANG ke API Midtrans (isi webhook tidak dipercaya) →
 * nominal cocok → lunas. Dipanggil di dalam tenant context.
 */
final class HandleMidtransNotification
{
    public function __construct(private readonly ConfirmOnlinePayment $confirm) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int status HTTP untuk Midtrans (200 = diterima; 4xx = ditolak, Midtrans akan mencoba ulang)
     */
    public function handle(array $payload): int
    {
        $setting = PaymentGatewaySetting::current();
        $orderId = (string) ($payload['order_id'] ?? '');

        if (blank($setting->server_key) || $orderId === '') {
            return 404;
        }

        $expected = MidtransClient::signature($orderId, (string) ($payload['status_code'] ?? ''), (string) ($payload['gross_amount'] ?? ''), (string) $setting->server_key);

        if (! hash_equals($expected, (string) ($payload['signature_key'] ?? ''))) {
            Log::warning('Notifikasi Midtrans dengan tanda tangan salah', ['order_id' => mb_substr($orderId, 0, 64)]);

            return 403;
        }

        $transaction = GatewayTransaction::query()->find($orderId);

        if ($transaction === null) {
            return 404;
        }

        // Sumber kebenaran: API Midtrans, bukan isi webhook
        $status = MidtransClient::for($setting)->status($orderId);
        $state = (string) ($status['transaction_status'] ?? '');
        $paid = $state === 'settlement' || ($state === 'capture' && ($status['fraud_status'] ?? 'accept') === 'accept');

        if ($paid) {
            if (Money::compare(Money::of((string) ($status['gross_amount'] ?? '0')), $transaction->amount) !== 0) {
                Log::error('Nominal Midtrans tidak cocok', ['transaction' => $transaction->id, 'gross_amount' => $status['gross_amount'] ?? null]);

                return 422;
            }

            $this->confirm->handle($transaction, [
                'transaction_id' => isset($status['transaction_id']) ? (string) $status['transaction_id'] : null,
                'payment_type' => isset($status['payment_type']) ? (string) $status['payment_type'] : null,
            ]);
        } elseif (in_array($state, ['expire', 'cancel', 'deny', 'failure'], true) && $transaction->status === GatewayTransaction::PENDING) {
            $transaction->forceFill(['status' => $state])->save();
        }

        return 200;
    }
}
