<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Enums\ErrorCode;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Penyesuaian stok manual (tambah/kurang + alasan). ID penyesuaian dari client adalah
 * idempotency key: request ganda tidak mengubah stok dua kali.
 */
final class AdjustStock
{
    /**
     * @return array{movement: StockMovement, product: Product, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(Product $product, User $by, string $id, int $qtyChange, string $reason): array
    {
        if (($existing = Idempotency::existing(StockMovement::class, $id)) !== null) {
            return ['movement' => $existing, 'product' => $product->refresh(), 'replayed' => true];
        }

        if (! $product->track_stock) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Produk ini tidak melacak stok', [
                'product_id' => ['Aktifkan lacak stok terlebih dahulu'],
            ]);
        }

        $movement = DB::transaction(function () use ($product, $by, $id, $qtyChange, $reason): StockMovement {
            // Kunci baris produk: penyesuaian & penjualan bersamaan tidak saling menimpa
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->stock_qty += $qtyChange;
            $locked->save();

            return StockMovement::query()->create([
                'id' => $id,
                'product_id' => $locked->id,
                'user_id' => $by->id,
                'type' => StockMovementType::Adjustment,
                'qty_change' => $qtyChange,
                'qty_after' => $locked->stock_qty,
                'reason' => trim($reason),
            ]);
        });

        return ['movement' => $movement, 'product' => $product->refresh(), 'replayed' => false];
    }
}
