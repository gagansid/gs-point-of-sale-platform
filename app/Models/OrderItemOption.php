<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot opsi item saat transaksi.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_item_id
 * @property string $option_id
 * @property string $option_name
 * @property string $price_delta
 */
final class OrderItemOption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['order_item_id', 'option_id', 'option_name', 'price_delta'];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2'];
    }
}
