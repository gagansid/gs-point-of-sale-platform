<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Enums\InvoicePaymentMethod;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owner mengirim bukti transfer manual (Q123). File sudah disimpan form di disk privat `local` folder bisnisnya; di sini
 * hanya dicek & dicatat. Tagihan menunggu verifikasi tidak kedaluwarsa dan tidak dibatalkan otomatis. Kirim ulang
 * selagi menunggu verifikasi ditolak (satu bukti per putaran; admin menolak → owner bisa kirim lagi).
 */
final class SubmitPaymentProof
{
    public const DIRECTORY = 'billing-proofs';

    /** @throws BusinessException */
    public function handle(SubscriptionInvoice $invoice, User $owner, string $path, string $sender, ?string $note = null): SubscriptionInvoice
    {
        $path = ltrim($path, '/');
        // Hanya file di folder bisnis ini (form menyimpan ke sana); mencegah menunjuk file lain di disk
        if (! str_starts_with($path, self::directory($invoice->tenant_id).'/') || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Bukti transfer tidak ditemukan. Unggah ulang', ['proof' => ['Bukti transfer tidak ditemukan']]);
        }

        $sender = trim($sender);
        if ($sender === '' || mb_strlen($sender) > 120) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nama pengirim wajib diisi', ['proof_sender' => ['Nama pengirim wajib diisi (maks. 120 karakter)']]);
        }

        return DB::transaction(function () use ($invoice, $owner, $path, $sender, $note): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Pending || $locked->due_at->isPast()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Tagihan tidak bisa dibayar lagi. Buat tagihan baru');
            }
            if ($locked->proof_submitted_at !== null) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Bukti transfer sudah dikirim dan sedang diverifikasi');
            }

            $old = $locked->proof_path;
            $locked->forceFill([
                'payment_method' => InvoicePaymentMethod::Manual,
                'proof_path' => $path,
                'proof_sender' => $sender,
                'proof_note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
                'proof_submitted_at' => now(),
                'proof_submitted_by' => $owner->id,
                'proof_rejected_reason' => null,
                'proof_rejected_at' => null,
            ])->save();

            // Bukti lama yang ditolak tidak disimpan lagi
            if ($old !== null && $old !== $path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($old));
            }

            Log::info('Bukti transfer tagihan dikirim', ['invoice' => $locked->number, 'tenant_id' => $locked->tenant_id, 'user_id' => $owner->id]);

            return $locked;
        });
    }

    public static function directory(string $tenantId): string
    {
        return self::DIRECTORY.'/'.$tenantId;
    }
}
