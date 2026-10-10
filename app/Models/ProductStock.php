<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok, stok minimum, dan ketersediaan satu produk di satu outlet (tabel outlet_product, ADR 0010).
 * stock_qty hanya berubah lewat AdjustStock / order (StockMovement).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $product_id
 * @property bool $is_available
 * @property int $stock_qty
 * @property int|null $min_stock
 */
final class ProductStock extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'outlet_product';

    protected $fillable = ['outlet_id', 'product_id', 'is_available', 'min_stock'];

    protected $attributes = ['is_available' => true, 'stock_qty' => 0];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'stock_qty' => 'integer', 'min_stock' => 'integer'];
    }

    /**
     * Baris stok produk di outlet; dibuat bila belum ada (produk baru / outlet baru).
     * Dengan $lock, baris dikunci agar penjualan & penyesuaian bersamaan tidak saling menimpa.
     */
    public static function for(string $productId, string $outletId, bool $lock = false): self
    {
        $query = self::query()->where('outlet_id', $outletId)->where('product_id', $productId);
        $stock = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($stock !== null) {
            return $stock;
        }

        $stock = new self(['outlet_id' => $outletId, 'product_id' => $productId]);
        $stock->save();

        return $lock ? self::query()->lockForUpdate()->findOrFail($stock->id) : $stock;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
