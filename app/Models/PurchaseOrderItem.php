<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris purchase order (ADR 0021): qty dipesan, qty sudah diterima (dari stok masuk yang diposting), harga perkiraan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property string $product_id
 * @property string $product_name
 * @property int $qty
 * @property int $received_qty
 * @property string $unit_cost
 * @property string $line_total
 * @property int $sort_order
 */
final class PurchaseOrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['purchase_order_id', 'product_id', 'product_name', 'qty', 'received_qty', 'unit_cost', 'line_total', 'sort_order'];

    protected $attributes = ['received_qty' => 0];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'received_qty' => 'integer', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function remaining(): int
    {
        return max(0, $this->qty - $this->received_qty);
    }
}
