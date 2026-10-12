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
use App\Models\StockOpname;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Posting stok opname (ADR 0015, Q81; izin stock.opname): untuk baris yang dihitung, selisih = fisik − stok outlet SAAT
 * posting (penjualan setelah lembar dibuat ikut terhitung); stok diset ke hitungan fisik dengan riwayat `opname`;
 * nilai selisih = selisih × harga modal saat ini (tanpa modal = 0). Baris tanpa hitungan dilewati. Idempotent.
 */
final class PostStockOpname
{
    /** @throws BusinessException */
    public function handle(StockOpname $opname, User $by): StockOpname
    {
        return DB::transaction(function () use ($opname, $by): StockOpname {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);

            if ($locked->status === StockDocumentStatus::Posted) {
                return $locked->load('items');
            }

            $items = $locked->items()->whereNotNull('counted_qty')->get();
            if ($items->isEmpty()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Isi qty fisik minimal satu produk sebelum posting');
            }

            $totalQty = 0;
            $totalValue = '0.00';

            foreach ($items as $item) {
                $stock = ProductStock::for($item->product_id, $locked->outlet_id, lock: true);
                $difference = (int) $item->counted_qty - $stock->stock_qty;
                $cost = Product::query()->withTrashed()->whereKey($item->product_id)->value('cost_price');
                $value = $cost !== null ? Money::mul(Money::of((string) $cost), $difference) : '0.00';

                $item->forceFill([
                    'stock_at_post' => $stock->stock_qty,
                    'difference' => $difference,
                    'unit_cost' => $cost,
                    'difference_value' => $value,
                ])->save();

                if ($difference !== 0) {
                    $stock->stock_qty = (int) $item->counted_qty;
                    $stock->save();

                    StockMovement::query()->create([
                        'outlet_id' => $locked->outlet_id,
                        'product_id' => $item->product_id,
                        'user_id' => $by->id,
                        'type' => StockMovementType::Opname,
                        'qty_change' => $difference,
                        'qty_after' => $stock->stock_qty,
                        'reason' => 'Stok opname '.$locked->number,
                        'reference_id' => $locked->id,
                    ]);
                }

                $totalQty += $difference;
                $totalValue = Money::add($totalValue, $value);
            }

            $locked->forceFill([
                'status' => StockDocumentStatus::Posted,
                'difference_qty' => $totalQty,
                'difference_value' => $totalValue,
                'posted_at' => now(),
                'posted_by' => $by->id,
            ])->save();

            return $locked->load('items');
        });
    }
}
