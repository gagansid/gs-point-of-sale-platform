<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer stok antar outlet (ADR 0015, Q82). Dua outlet, jadi tanpa OutletScope: terlihat bila user memegang outlet
 * asal atau tujuan (scopeVisibleTo); aksi dicek per sisi (kirim/batal = asal, terima = tujuan).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $from_outlet_id
 * @property string $to_outlet_id
 * @property StockTransferStatus $status
 * @property string|null $notes
 * @property CarbonImmutable|null $sent_at
 * @property string|null $sent_by
 * @property CarbonImmutable|null $received_at
 * @property string|null $received_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 */
final class StockTransfer extends Model
{
    use BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['from_outlet_id', 'to_outlet_id', 'notes'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function fromOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function toOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Transfer dari/ke salah satu outlet ini.
     *
     * @param  Builder<StockTransfer>  $query
     * @param  list<string>  $outletIds
     */
    public function scopeTouching(Builder $query, array $outletIds): void
    {
        $query->where(fn (Builder $q) => $q->whereIn('from_outlet_id', $outletIds)->orWhereIn('to_outlet_id', $outletIds));
    }

    public function isDraft(): bool
    {
        return $this->status === StockTransferStatus::Draft;
    }
}
