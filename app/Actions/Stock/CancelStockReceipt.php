<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan stok masuk yang sudah diposting (ADR 0015, Q79; izin stock.receive): stok outlet dikurangi lagi (riwayat
 * `purchase_void`, boleh minus), alasan wajib. Modal tidak diputar balik (Q80). Draf cukup dihapus. Idempotent.
 */
final class CancelStockReceipt
{
    /** @throws BusinessException */
    public function handle(StockReceipt $receipt, User $by, string $reason): StockReceipt
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi (maks. 255 karakter)', ['reason' => ['Alasan wajib diisi (maks. 255 karakter)']]);
        }

        return DB::transaction(function () use ($receipt, $by, $reason): StockReceipt {
            $locked = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status === StockDocumentStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== StockDocumentStatus::Posted) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Draf tidak perlu dibatalkan, hapus saja');
            }

            foreach ($locked->items()->get() as $item) {
                $stock = ProductStock::for($item->product_id, $locked->outlet_id, lock: true);
                $stock->stock_qty -= $item->qty;
                $stock->save();

                StockMovement::query()->create([
                    'outlet_id' => $locked->outlet_id,
                    'product_id' => $item->product_id,
                    'user_id' => $by->id,
                    'type' => StockMovementType::PurchaseVoid,
                    'qty_change' => -$item->qty,
                    'qty_after' => $stock->stock_qty,
                    'reason' => 'Batal '.$locked->number.': '.$reason,
                    'reference_id' => $locked->id,
                ]);
            }

            $locked->forceFill([
                'status' => StockDocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
