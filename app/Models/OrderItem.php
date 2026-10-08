<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item order dengan snapshot nama & harga produk saat transaksi.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $product_id
 * @property string $product_name
 * @property string $unit_price
 * @property string $options_total
 * @property int $qty
 * @property string $discount
 * @property string $line_total
 * @property string|null $notes
 * @property int $sort_order
 */
final class OrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['order_id', 'product_id', 'product_name', 'unit_price', 'options_total', 'qty', 'discount', 'line_total', 'notes', 'sort_order'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'options_total' => 'decimal:2',
            'qty' => 'integer',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<OrderItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
