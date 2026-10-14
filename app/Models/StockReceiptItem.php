<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris stok masuk: produk (snapshot nama), qty, harga beli, modal sebelum/sesudah posting (Q80).
 *
 * @property string $id
 * @property string $stock_receipt_id
 * @property string $product_id
 * @property string $product_name
 * @property int $qty
 * @property string $unit_cost
 * @property string $line_total
 * @property string|null $cost_before
 * @property string|null $cost_after
 * @property int $sort_order
 */
final class StockReceiptItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['stock_receipt_id', 'product_id', 'product_name', 'qty', 'unit_cost', 'line_total', 'sort_order'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_before' => 'decimal:2',
            'cost_after' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<StockReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }
}
