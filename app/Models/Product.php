<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $category_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $price
 * @property string|null $cost_price
 * @property bool $track_stock
 * @property int $stock_qty
 * @property string|null $image_path
 * @property bool $is_active
 * @property bool $is_available
 * @property bool $is_favorite
 * @property int|null $min_stock
 * @property int $sort_order
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** stock_qty sengaja tidak fillable: hanya berubah lewat AdjustStock / order (StockMovement). */
    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'barcode',
        'price',
        'cost_price',
        'track_stock',
        'image_path',
        'is_active',
        'is_available',
        'is_favorite',
        'min_stock',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'track_stock' => 'boolean',
            'stock_qty' => 'integer',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'is_favorite' => 'boolean',
            'min_stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Stok habis/minus untuk produk yang dilacak stoknya (ditandai di dashboard). */
    public function isOutOfStock(): bool
    {
        return $this->track_stock && $this->stock_qty <= 0;
    }

    /** Stok masih ada tapi sudah di bawah/sama dengan batas minimum (peringatan "stok menipis"). */
    public function isLowStock(): bool
    {
        return $this->track_stock && $this->min_stock !== null && $this->stock_qty > 0 && $this->stock_qty <= $this->min_stock;
    }

    /**
     * Padanan query isLowStock().
     *
     * @param  Builder<self>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('track_stock', true)
            ->whereNotNull('min_stock')
            ->where('stock_qty', '>', 0)
            ->whereColumn('stock_qty', '<=', 'min_stock');
    }

    /**
     * Tidak bisa dijual sekarang: ditandai habis atau stok yang dilacak ≤ 0.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSoldOut(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('is_available', false)
            ->orWhere(fn (Builder $q) => $q->where('track_stock', true)->where('stock_qty', '<=', 0)));
    }

    public function imageUrl(): ?string
    {
        // Absolut untuk aplikasi Flutter; host mengikuti request (mis. https://api.gspos.id/storage/...)
        return $this->image_path !== null ? url(Storage::disk('public')->url($this->image_path)) : null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<OptionGroup, $this> */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_groups')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
