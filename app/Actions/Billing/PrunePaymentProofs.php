<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Bersihkan file bukti transfer (Q127, harian): (1) tagihan batal/kedaluwarsa lebih dari
 * pos.billing.proof_retention_days → file dihapus & path dikosongkan; (2) file yatim di billing-proofs (unggahan
 * yang tidak jadi dikirim / tidak dirujuk tagihan mana pun) lebih dari 1 hari. Bukti tagihan lunas & yang masih
 * menunggu tidak pernah dihapus (catatan pembayaran). Aman diulang.
 *
 * @return array{expired: int, orphans: int}
 */
final class PrunePaymentProofs
{
    /** @return array{expired: int, orphans: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $disk = Storage::disk('local');
        $cutoff = $now->subDays(max(1, (int) config('pos.billing.proof_retention_days')));

        $expired = 0;
        SubscriptionInvoice::query()->withoutGlobalScopes()
            ->whereIn('status', [InvoiceStatus::Cancelled, InvoiceStatus::Expired])
            ->whereNotNull('proof_path')->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($invoices) use ($disk, &$expired): void {
                foreach ($invoices as $invoice) {
                    $disk->delete((string) $invoice->proof_path);
                    // Tanpa mengubah updated_at: riwayat tagihan tetap menunjukkan kapan dibatalkan
                    SubscriptionInvoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->update(['proof_path' => null]);
                    $expired++;
                }
            });

        // File yang dirujuk tagihan (lunas, menunggu, atau belum lewat masa simpan) tidak boleh terhapus
        $referenced = array_flip(SubscriptionInvoice::query()->withoutGlobalScopes()->whereNotNull('proof_path')->pluck('proof_path')->all());
        $orphanCutoff = $now->subDay()->getTimestamp();
        $orphans = 0;
        foreach ($disk->allFiles(SubmitPaymentProof::DIRECTORY) as $path) {
            if (! isset($referenced[$path]) && $disk->lastModified($path) < $orphanCutoff) {
                $disk->delete($path);
                $orphans++;
            }
        }

        if ($expired + $orphans > 0) {
            Log::info('Bukti transfer lama dibersihkan', ['expired' => $expired, 'orphans' => $orphans]);
        }

        return ['expired' => $expired, 'orphans' => $orphans];
    }
}
