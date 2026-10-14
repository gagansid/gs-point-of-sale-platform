<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris retur: item order, qty, dan nilai (snapshot nama produk), ADR 0018.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_refund_id
 * @property string $order_item_id
 * @property string $product_name
 * @property int $qty
 * @property string $amount
 */
final class OrderRefundItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['order_refund_id', 'order_item_id', 'product_name', 'qty', 'amount'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
