<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grup opsi: min_select 1 = wajib pilih (mis. Ukuran), max_select = batas pilihan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $min_select
 * @property int $max_select
 * @property int $sort_order
 * @property bool $is_active
 */
final class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'min_select', 'max_select', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['min_select' => 'integer', 'max_select' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return HasMany<Option, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_groups');
    }
}
