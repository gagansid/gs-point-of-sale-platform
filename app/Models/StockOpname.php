<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockDocumentStatus;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stok opname per outlet (ADR 0015, Q81): draf lembar hitung → diposting (stok = hitungan fisik, selisih tercatat).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $number
 * @property string|null $category_id
 * @property CarbonImmutable $counted_on
 * @property string|null $notes
 * @property StockDocumentStatus $status
 * @property int $difference_qty
 * @property string $difference_value
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by
 */
final class StockOpname extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'category_id', 'counted_on', 'notes'];

    protected $attributes = ['status' => 'draft', 'difference_qty' => 0, 'difference_value' => '0.00'];

    protected function casts(): array
    {
        return [
            'status' => StockDocumentStatus::class,
            'counted_on' => 'immutable_date',
            'difference_qty' => 'integer',
            'difference_value' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockOpnameItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === StockDocumentStatus::Draft;
    }
}
