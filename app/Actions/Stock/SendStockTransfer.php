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
 * Kirim transfer (ADR 0015, Q82): stok outlet asal berkurang (riwayat `transfer_out`, boleh minus), status dalam
 * perjalanan. Hanya pemegang outlet asal. Kirim ulang = hasil sama.
 */
final class SendStockTransfer
{
    /** @throws BusinessException */
    public function handle(StockTransfer $transfer, User $by): StockTransfer
    {
        if (! $by->canAccessOutlet($transfer->from_outlet_id)) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($transfer, $by): StockTransfer {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->status !== StockTransferStatus::Draft) {
                return $locked->status === StockTransferStatus::InTransit
                    ? $locked->load('items')
                    : throw BusinessException::of(ErrorCode::ValidationError, 'Transfer ini sudah '.mb_strtolower($locked->status->getLabel()));
            }

            foreach ($locked->items()->get() as $item) {
                $stock = ProductStock::for($item->product_id, $locked->from_outlet_id, lock: true);
                $stock->stock_qty -= $item->qty_sent;
                $stock->save();

                StockMovement::query()->create([
                    'outlet_id' => $locked->from_outlet_id,
                    'product_id' => $item->product_id,
                    'user_id' => $by->id,
                    'type' => StockMovementType::TransferOut,
                    'qty_change' => -$item->qty_sent,
                    'qty_after' => $stock->stock_qty,
                    'reason' => 'Transfer '.$locked->number,
                    'reference_id' => $locked->id,
                ]);
            }

            $locked->forceFill(['status' => StockTransferStatus::InTransit, 'sent_at' => now(), 'sent_by' => $by->id])->save();

            return $locked->load('items');
        });
    }
}
