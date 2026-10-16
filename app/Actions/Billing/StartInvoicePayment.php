<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use Illuminate\Support\Str;

/**
 * Bayar tagihan lewat Midtrans vendor (ADR 0024): Snap dibuat sekali per tagihan (URL dipakai ulang). Tanpa key vendor
 * → PAYMENT_GATEWAY_NOT_CONFIGURED (owner memakai transfer manual).
 */
final class StartInvoicePayment
{
    /** @throws BusinessException */
    public function handle(SubscriptionInvoice $invoice, User $owner, string $finishUrl): string
    {
        if ($invoice->status !== InvoiceStatus::Pending || $invoice->due_at->isPast()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tagihan tidak bisa dibayar lagi. Buat tagihan baru');
        }

        if ($invoice->snap_url !== null) {
            return $invoice->snap_url;
        }

        $client = MidtransClient::vendor() ?? throw BusinessException::of(ErrorCode::PaymentGatewayNotConfigured, 'Pembayaran online belum tersedia. Gunakan transfer manual');
        $orderId = 'BILL-'.$invoice->number.'-'.Str::lower(Str::random(4));
        $snap = $client->createSnap($orderId, (int) $invoice->amount, ['first_name' => $owner->name, 'email' => $owner->email], $finishUrl);

        $invoice->forceFill(['gateway_order_id' => $orderId, 'snap_url' => $snap['redirect_url']])->save();

        return $snap['redirect_url'];
    }
}
