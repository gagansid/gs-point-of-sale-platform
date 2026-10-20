<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Enums\InvoicePaymentMethod;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use Illuminate\Support\Str;

/**
 * Bayar tagihan lewat Midtrans vendor (ADR 0024, Q123): owner memilih QRIS atau VA; Snap dibuat sekali per tagihan per
 * metode (URL dipakai ulang). Ganti metode = transaksi Snap lama dibatalkan, transaksi baru dibuat. Tanpa key vendor
 * → PAYMENT_GATEWAY_NOT_CONFIGURED (owner memakai transfer manual).
 */
final class StartInvoicePayment
{
    /** @throws BusinessException */
    public function handle(SubscriptionInvoice $invoice, User $owner, string $finishUrl, InvoicePaymentMethod $method = InvoicePaymentMethod::Qris): string
    {
        if ($invoice->status !== InvoiceStatus::Pending || $invoice->due_at->isPast()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tagihan tidak bisa dibayar lagi. Buat tagihan baru');
        }
        if ($invoice->proof_submitted_at !== null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Bukti transfer sedang diverifikasi. Tunggu hasilnya sebelum membayar dengan cara lain');
        }
        if (! $method->isOnline()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Transfer manual memakai unggah bukti transfer');
        }

        if ($invoice->snap_url !== null && $invoice->payment_method === $method) {
            return $invoice->snap_url;
        }

        $client = MidtransClient::vendor() ?? throw BusinessException::of(ErrorCode::PaymentGatewayNotConfigured, 'Pembayaran online belum tersedia. Gunakan transfer manual');

        // Metode lain dipilih: transaksi lama dibatalkan agar tidak terbayar dua kali
        if ($invoice->gateway_order_id !== null) {
            $client->cancel($invoice->gateway_order_id);
        }

        $orderId = 'BILL-'.$invoice->number.'-'.Str::lower(Str::random(4));
        $snap = $client->createSnap($orderId, (int) $invoice->amount, ['first_name' => $owner->name, 'email' => $owner->email], $finishUrl, $method->snapChannels());

        $invoice->forceFill(['gateway_order_id' => $orderId, 'snap_url' => $snap['redirect_url'], 'payment_method' => $method])->save();

        return $snap['redirect_url'];
    }
}
