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
 * Dokumen stok masuk per outlet (ADR 0015, Q79): draf → diposting (stok bertambah, modal rata-rata tertimbang) →
 * dibatalkan. Ditulis lewat App\Actions\Stock\*.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $number
 * @property string|null $supplier_id
 * @property CarbonImmutable $received_on
 * @property string|null $invoice_number
 * @property string|null $notes
 * @property StockDocumentStatus $status
 * @property string $total_cost
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 */
final class StockReceipt extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'supplier_id', 'received_on', 'invoice_number', 'notes'];

    protected $attributes = ['status' => 'draft', 'total_cost' => '0.00'];

    protected function casts(): array
    {
        return [
            'status' => StockDocumentStatus::class,
            'received_on' => 'immutable_date',
            'total_cost' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === StockDocumentStatus::Draft;
    }
}
