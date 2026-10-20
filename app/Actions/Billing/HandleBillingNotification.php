<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use App\Services\Payment\MidtransClient;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Midtrans vendor untuk tagihan langganan (ADR 0024): tanda tangan dengan server key vendor, status dicek
 * ulang ke API Midtrans, nominal harus sama. Mengembalikan kode HTTP.
 */
final class HandleBillingNotification
{
    public function __construct(private readonly MarkInvoicePaid $markPaid) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): int
    {
        $client = MidtransClient::vendor();
        $orderId = (string) ($payload['order_id'] ?? '');

        if ($client === null || $orderId === '') {
            return 404;
        }

        $expected = MidtransClient::signature($orderId, (string) ($payload['status_code'] ?? ''), (string) ($payload['gross_amount'] ?? ''), (string) MidtransClient::vendorServerKey());
        if (! hash_equals($expected, (string) ($payload['signature_key'] ?? ''))) {
            Log::warning('Notifikasi tagihan dengan tanda tangan salah', ['order_id' => mb_substr($orderId, 0, 64)]);

            return 403;
        }

        $invoice = SubscriptionInvoice::query()->withoutGlobalScopes()->where('gateway_order_id', $orderId)->first();
        if ($invoice === null) {
            return 404;
        }

        $status = $client->status($orderId);
        $state = (string) ($status['transaction_status'] ?? '');
        $paid = $state === 'settlement' || ($state === 'capture' && ($status['fraud_status'] ?? 'accept') === 'accept');

        if ($paid) {
            if (Money::compare(Money::of((string) ($status['gross_amount'] ?? '0')), $invoice->amount) !== 0) {
                Log::error('Nominal tagihan Midtrans tidak cocok', ['invoice' => $invoice->id, 'gross_amount' => $status['gross_amount'] ?? null]);

                return 422;
            }

            // Dibayar walau sudah lewat jatuh tempo / dibatalkan: uang sudah masuk, tagihan tetap diakui
            $this->markPaid->handle($invoice, 'midtrans');
        } elseif (in_array($state, ['expire', 'cancel', 'deny', 'failure'], true) && $invoice->status === InvoiceStatus::Pending) {
            $invoice->forceFill(['snap_url' => null, 'gateway_order_id' => null])->save();
        }

        return 200;
    }
}
