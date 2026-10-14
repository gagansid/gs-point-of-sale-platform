<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order ke supplier (ADR 0021): draf → dikirim → diterima sebagian/penuh lewat Stok masuk, atau dibatalkan.
 * Ditulis lewat App\Actions\Stock\*PurchaseOrder.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $number
 * @property string $supplier_id
 * @property CarbonImmutable $ordered_on
 * @property CarbonImmutable|null $expected_on
 * @property string|null $notes
 * @property PurchaseOrderStatus $status
 * @property string $total_cost
 * @property CarbonImmutable|null $sent_at
 * @property string|null $sent_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 */
final class PurchaseOrder extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'supplier_id', 'ordered_on', 'expected_on', 'notes'];

    protected $attributes = ['status' => 'draft', 'total_cost' => '0.00'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_on' => 'immutable_date',
            'expected_on' => 'immutable_date',
            'total_cost' => 'decimal:2',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<StockReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class)->orderBy('created_at');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
