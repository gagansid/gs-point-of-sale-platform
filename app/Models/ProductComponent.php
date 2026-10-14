<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris resep / isi paket (ADR 0020): $qty satuan komponen per 1 produk.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $component_id
 * @property int $qty
 * @property int $sort_order
 */
final class ProductComponent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['product_id', 'component_id', 'qty', 'sort_order'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id')->withTrashed();
    }
}
