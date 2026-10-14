<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundMethod;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur sebagian per item (ADR 0018). Ditulis lewat App\Actions\Order\RefundOrder; tidak bisa diubah/dihapus.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $order_id
 * @property string $shift_id
 * @property string $device_id
 * @property string $user_id
 * @property string|null $approved_by
 * @property string $reason
 * @property RefundMethod $method
 * @property bool $restock
 * @property string $amount nilai retur (bagian grand total)
 * @property string $payout_amount uang yang dikembalikan
 * @property int $points_returned poin pembayaran yang dikembalikan
 * @property int $points_reversed poin hasil belanja yang ditarik
 * @property string|null $cash_movement_id
 * @property CarbonImmutable $created_at
 */
final class OrderRefund extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'order_id', 'shift_id', 'device_id', 'user_id', 'approved_by', 'reason', 'method', 'restock', 'amount', 'payout_amount', 'points_returned', 'points_reversed', 'cash_movement_id'];

    protected function casts(): array
    {
        return [
            'method' => RefundMethod::class,
            'restock' => 'boolean',
            'amount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'points_returned' => 'integer',
            'points_reversed' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderRefundItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderRefundItem::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
