<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin menolak bukti transfer (Q123): tagihan kembali menunggu bayar (jatuh tempo tetap), alasan tampil ke owner,
 * owner bisa mengunggah bukti baru. File bukti disimpan sampai diganti agar bisa dirujuk.
 */
final class RejectPaymentProof
{
    /** @throws BusinessException */
    public function handle(SubscriptionInvoice $invoice, Admin $admin, string $reason): SubscriptionInvoice
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan penolakan wajib diisi', ['reason' => ['Alasan wajib diisi (maks. 500 karakter)']]);
        }

        return DB::transaction(function () use ($invoice, $admin, $reason): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if (! $locked->awaitingVerification()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Tidak ada bukti transfer yang menunggu verifikasi');
            }

            // Lewat jatuh tempo selama diverifikasi: beri waktu owner mengirim ulang
            $dueAt = $locked->due_at->isPast() ? now()->addDays((int) config('pos.billing.due_days')) : $locked->due_at;

            $locked->forceFill([
                'proof_submitted_at' => null,
                'proof_rejected_reason' => $reason,
                'proof_rejected_at' => now(),
                'due_at' => $dueAt,
            ])->save();

            Log::info('Bukti transfer tagihan ditolak', ['invoice' => $locked->number, 'admin_id' => $admin->id]);

            return $locked;
        });
    }
}
