<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\User;
use App\Services\Stock\WeightedCost;
use Illuminate\Support\Facades\DB;

/**
 * Posting stok masuk (ADR 0015, Q79–Q80; izin stock.receive): stok outlet bertambah (riwayat `purchase`), modal
 * produk dihitung ulang rata-rata tertimbang (baris produk & stok dikunci), dokumen terkunci. Posting ulang = hasil
 * sama (idempotent).
 */
final class PostStockReceipt
{
    /** @throws BusinessException */
    public function handle(StockReceipt $receipt, User $by): StockReceipt
    {
        return DB::transaction(function () use ($receipt, $by): StockReceipt {
            $locked = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->status === StockDocumentStatus::Posted) {
                return $locked->load('items');
            }

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Stok masuk yang dibatalkan tidak bisa diposting');
            }

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Stok masuk belum berisi item', ['items' => ['Tambahkan minimal satu item']]);
            }

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->find($item->product_id);

                if ($product === null || ! $product->track_stock) {
                    throw BusinessException::of(ErrorCode::ValidationError, "{$item->product_name} tidak ditemukan atau tidak melacak stok");
                }

                // Modal dari stok sebelum barang ini masuk (Q80)
                $cost = WeightedCost::apply($product, $item->qty, $item->unit_cost);

                $stock = ProductStock::for($product->id, $locked->outlet_id, lock: true);
                $stock->stock_qty += $item->qty;
                $stock->save();

                StockMovement::query()->create([
                    'outlet_id' => $locked->outlet_id,
                    'product_id' => $product->id,
                    'user_id' => $by->id,
                    'type' => StockMovementType::Purchase,
                    'qty_change' => $item->qty,
                    'qty_after' => $stock->stock_qty,
                    'reason' => 'Stok masuk '.$locked->number,
                    'reference_id' => $locked->id,
                ]);

                $item->forceFill(['cost_before' => $cost['before'], 'cost_after' => $cost['after']])->save();
            }

            $locked->forceFill(['status' => StockDocumentStatus::Posted, 'posted_at' => now(), 'posted_by' => $by->id])->save();

            return $locked->load('items');
        });
    }
}
