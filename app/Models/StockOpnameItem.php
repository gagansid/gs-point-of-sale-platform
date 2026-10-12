<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris lembar hitung: stok sistem saat dibuat, hitungan fisik (null = dilewati), stok & selisih saat posting.
 *
 * @property string $id
 * @property string $stock_opname_id
 * @property string $product_id
 * @property string $product_name
 * @property int $system_qty
 * @property int|null $counted_qty
 * @property int|null $stock_at_post
 * @property int|null $difference
 * @property string|null $unit_cost
 * @property string|null $difference_value
 * @property int $sort_order
 */
final class StockOpnameItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['stock_opname_id', 'product_id', 'product_name', 'system_qty', 'counted_qty', 'sort_order'];

    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
            'stock_at_post' => 'integer',
            'difference' => 'integer',
            'unit_cost' => 'decimal:2',
            'difference_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
