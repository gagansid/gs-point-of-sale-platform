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
 * Terima transfer di outlet tujuan (ADR 0015, Q82): stok tujuan bertambah sebesar qty DITERIMA (riwayat `transfer_in`);
 * qty diterima 0–dikirim, selisih = hilang/rusak di jalan (tidak kembali ke asal). Hanya pemegang outlet tujuan.
 * Terima ulang = hasil sama.
 */
final class ReceiveStockTransfer
{
    /**
     * @param  array<string, int|string|null>  $received  item_id => qty diterima; tidak ada = sama dengan dikirim
     *
     * @throws BusinessException
     */
    public function handle(StockTransfer $transfer, User $by, array $received = []): StockTransfer
    {
        if (! $by->canAccessOutlet($transfer->to_outlet_id)) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($transfer, $by, $received): StockTransfer {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->status === StockTransferStatus::Received) {
                return $locked->load('items');
            }

            if ($locked->status !== StockTransferStatus::InTransit) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Hanya transfer dalam perjalanan yang bisa diterima');
            }

            $items = $locked->items()->get();
            foreach ($items as $item) {
                $qty = $received[$item->id] ?? $item->qty_sent;

                if (! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 0 || (int) $qty > $item->qty_sent) {
                    throw BusinessException::of(ErrorCode::ValidationError, "Qty diterima {$item->product_name} harus 0–{$item->qty_sent}", [
                        "received.{$item->id}" => ["Qty diterima harus 0–{$item->qty_sent}"],
                    ]);
                }

                $item->forceFill(['qty_received' => (int) $qty])->save();
            }

            foreach ($items as $item) {
                if ($item->qty_received === 0) {
                    continue;
                }

                $stock = ProductStock::for($item->product_id, $locked->to_outlet_id, lock: true);
                $stock->stock_qty += (int) $item->qty_received;
                $stock->save();

                StockMovement::query()->create([
                    'outlet_id' => $locked->to_outlet_id,
                    'product_id' => $item->product_id,
                    'user_id' => $by->id,
                    'type' => StockMovementType::TransferIn,
                    'qty_change' => (int) $item->qty_received,
                    'qty_after' => $stock->stock_qty,
                    'reason' => 'Transfer '.$locked->number.($item->qty_received < $item->qty_sent ? ' (kurang '.($item->qty_sent - $item->qty_received).')' : ''),
                    'reference_id' => $locked->id,
                ]);
            }

            $locked->forceFill(['status' => StockTransferStatus::Received, 'received_at' => now(), 'received_by' => $by->id])->save();

            return $locked->load('items');
        });
    }
}
