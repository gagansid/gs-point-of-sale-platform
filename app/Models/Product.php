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
 * @property int $stock_qty dari outlet_product, hanya terisi lewat scope atOutlet()
 * @property string|null $image_path
 * @property bool $is_active
 * @property bool $is_available dari outlet_product (atOutlet)
 * @property bool $is_listed dijual di outlet, dari outlet_product (atOutlet, ADR 0011)
 * @property bool $is_favorite
 * @property int|null $min_stock dari outlet_product (atOutlet)
 * @property int $sort_order
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** Stok, stok minimum, dan ketersediaan disimpan per outlet di ProductStock (ADR 0010). */
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
        'is_favorite',
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
            'is_listed' => 'boolean',
            'is_favorite' => 'boolean',
            'min_stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Menambahkan stock_qty, min_stock, is_available, dan is_listed milik satu outlet sebagai atribut produk.
     * Subquery (bukan join) agar kolom products tidak ambigu saat sort/filter.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAtOutlet(Builder $query, string $outletId): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $row = 'from outlet_product op where op.product_id = products.id and op.outlet_id = ? limit 1';

        // Baris stok belum ada (produk/outlet baru) = stok 0, tersedia, dijual
        $query->selectRaw("coalesce((select op.stock_qty {$row}), 0) as stock_qty", [$outletId])
            ->selectRaw("(select op.min_stock {$row}) as min_stock", [$outletId])
            ->selectRaw("coalesce((select op.is_available {$row}), 1) as is_available", [$outletId])
            ->selectRaw("coalesce((select op.is_listed {$row}), 1) as is_listed", [$outletId]);
    }

    /**
     * Mengisi stock_qty, min_stock, is_available, dan is_listed dari outlet tertentu ke instance ini
     * (setelah route model binding / refresh, yang tidak melewati scope atOutlet).
     */
    public function loadOutletState(string $outletId): static
    {
        $stock = $this->stocks()->where('outlet_id', $outletId)->first();

        $this->setRawAttributes(array_merge($this->getAttributes(), [
            'stock_qty' => $stock->stock_qty ?? 0,
            'min_stock' => $stock?->min_stock,
            'is_available' => $stock->is_available ?? true,
            'is_listed' => $stock->is_listed ?? true,
        ]), sync: true);

        return $this;
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
     * Padanan query isLowStock() di satu outlet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLowStock(Builder $query, string $outletId): void
    {
        $query->where('track_stock', true)
            ->whereHas('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)
                ->whereNotNull('min_stock')
                ->where('stock_qty', '>', 0)
                ->whereColumn('stock_qty', '<=', 'min_stock'));
    }

    /**
     * Stok yang dilacak ≤ 0 di satu outlet (baris stok belum ada = 0).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutOfStock(Builder $query, string $outletId): void
    {
        $query->where('track_stock', true)
            ->whereDoesntHave('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)->where('stock_qty', '>', 0));
    }

    /**
     * Tidak bisa dijual sekarang di satu outlet: ditandai habis atau stok yang dilacak ≤ 0.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSoldOut(Builder $query, string $outletId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)->where('is_available', false))
            ->orWhere(fn (Builder $q) => $q->outOfStock($outletId)));
    }

    /**
     * Ketersediaan di satu outlet (padanan is_available dari atOutlet).
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailableAt(Builder $query, string $outletId, bool $available = true): void
    {
        $available
            ? $query->whereDoesntHave('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)->where('is_available', false))
            : $query->whereHas('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)->where('is_available', false));
    }

    /**
     * Dijual di satu outlet (ADR 0011 / Q42); tanpa baris outlet_product = dijual.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListedAt(Builder $query, string $outletId): void
    {
        $query->whereDoesntHave('stocks', fn (Builder $stock) => $stock->where('outlet_id', $outletId)->where('is_listed', false));
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

    /** @return HasMany<ProductStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
