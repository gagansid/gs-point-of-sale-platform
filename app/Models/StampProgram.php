<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Program kartu stempel (ADR 0013, Q65): produk/kategori terpilih, 1 item = 1 stempel, `stamps_required` stempel =
 * 1 item gratis (item termurah yang memenuhi). Beberapa program boleh aktif bersamaan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $stamps_required
 * @property bool $is_active
 */
final class StampProgram extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['name', 'stamps_required', 'is_active'];

    protected $attributes = ['is_active' => true];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['name', 'stamps_required', 'is_active'];
    }

    protected function casts(): array
    {
        return ['stamps_required' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<StampProgramItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StampProgramItem::class);
    }

    /** @return list<string> */
    public function productIds(): array
    {
        return $this->items->pluck('product_id')->filter()->values()->all();
    }

    /** @return list<string> */
    public function categoryIds(): array
    {
        return $this->items->pluck('category_id')->filter()->values()->all();
    }

    /** Produk ini (atau kategorinya) termasuk program. Relasi `items` sebaiknya sudah dimuat. */
    public function covers(?string $productId, ?string $categoryId): bool
    {
        return ($productId !== null && in_array($productId, $this->productIds(), true))
            || ($categoryId !== null && in_array($categoryId, $this->categoryIds(), true));
    }
}
