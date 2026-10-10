<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan stok. Hanya ditambah, tidak pernah diubah atau dihapus.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $outlet_id
 * @property string $product_id
 * @property string|null $user_id
 * @property StockMovementType $type
 * @property int $qty_change
 * @property int $qty_after
 * @property string|null $reason
 * @property string|null $reference_id
 */
final class StockMovement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['id', 'outlet_id', 'product_id', 'user_id', 'type', 'qty_change', 'qty_after', 'reason', 'reference_id'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'qty_change' => 'integer',
            'qty_after' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
