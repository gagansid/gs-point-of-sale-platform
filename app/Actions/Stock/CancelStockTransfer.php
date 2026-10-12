<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan transfer yang masih dalam perjalanan (ADR 0015, Q82): stok kembali ke outlet asal (riwayat
 * `transfer_cancel`), alasan wajib. Hanya pemegang outlet asal. Draf cukup dihapus; yang sudah diterima tidak bisa.
 */
final class CancelStockTransfer
{
    /** @throws BusinessException */
    public function handle(StockTransfer $transfer, User $by, string $reason): StockTransfer
    {
        if (! $by->canAccessOutlet($transfer->from_outlet_id)) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi (maks. 255 karakter)', ['reason' => ['Alasan wajib diisi (maks. 255 karakter)']]);
        }

        return DB::transaction(function () use ($transfer, $by, $reason): StockTransfer {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->status === StockTransferStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== StockTransferStatus::InTransit) {
                throw BusinessException::of(ErrorCode::ValidationError, $locked->isDraft() ? 'Draf tidak perlu dibatalkan, hapus saja' : 'Transfer yang sudah diterima tidak bisa dibatalkan');
            }

            foreach ($locked->items()->get() as $item) {
                $stock = ProductStock::for($item->product_id, $locked->from_outlet_id, lock: true);
                $stock->stock_qty += $item->qty_sent;
                $stock->save();

                StockMovement::query()->create([
                    'outlet_id' => $locked->from_outlet_id,
                    'product_id' => $item->product_id,
                    'user_id' => $by->id,
                    'type' => StockMovementType::TransferCancel,
                    'qty_change' => $item->qty_sent,
                    'qty_after' => $stock->stock_qty,
                    'reason' => 'Batal '.$locked->number.': '.$reason,
                    'reference_id' => $locked->id,
                ]);
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
