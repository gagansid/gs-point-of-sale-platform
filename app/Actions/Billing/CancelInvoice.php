<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\SubscriptionInvoice;

/** Batalkan tagihan yang belum lunas (admin, ADR 0024). */
final class CancelInvoice
{
    /** @throws BusinessException */
    public function handle(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tagihan lunas tidak bisa dibatalkan');
        }

        $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

        return $invoice;
    }
}
