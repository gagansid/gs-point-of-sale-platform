<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bahan yang dipakai sebuah opsi (ADR 0020), mis. "Extra shot" = 18 gram kopi.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $option_id
 * @property string $component_id
 * @property int $qty
 */
final class OptionComponent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['option_id', 'component_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id')->withTrashed();
    }
}
