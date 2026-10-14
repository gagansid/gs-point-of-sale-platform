<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan PO yang masih menunggu barang (ADR 0021): alasan wajib; stok masuk yang sudah diposting tetap. Draf PO
 * dihapus saja. Batal ulang = hasil sama.
 */
final class CancelPurchaseOrder
{
    /** @throws BusinessException */
    public function handle(PurchaseOrder $order, User $by, string $reason): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi (maks. 255 karakter)', ['reason' => ['Alasan wajib diisi (maks. 255 karakter)']]);
        }

        return DB::transaction(function () use ($order, $by, $reason): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === PurchaseOrderStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->status->isOpen()) {
                throw BusinessException::of(ErrorCode::ValidationError, $locked->isDraft() ? 'Draf PO cukup dihapus' : 'PO yang sudah diterima tidak bisa dibatalkan');
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
