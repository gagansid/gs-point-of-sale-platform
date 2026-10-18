<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris tagihan langganan (ADR 0025 C2): paket atau add-on, nama & harga snapshot. Diakses lewat tagihannya
 * (tagihan sudah dibatasi tenant), ditulis CreateInvoice / CreateAddonInvoice.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $type plan / addon
 * @property string|null $plan_id
 * @property string|null $addon_id
 * @property string $name
 * @property int $quantity
 * @property string $unit_price
 * @property string $amount
 * @property int $sort_order
 */
final class SubscriptionInvoiceItem extends Model
{
    use HasUuids;

    public const PLAN = 'plan';

    public const ADDON = 'addon';

    protected $fillable = ['type', 'plan_id', 'addon_id', 'name', 'quantity', 'unit_price', 'amount', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<Addon, $this> */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
