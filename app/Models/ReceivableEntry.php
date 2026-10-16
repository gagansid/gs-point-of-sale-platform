<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReceivableEntryType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger kasbon member (ADR 0023), append-only. Ditulis lewat App\Services\Member\ReceivableLedger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $member_id
 * @property string|null $outlet_id
 * @property string|null $order_id
 * @property string|null $payment_id
 * @property ReceivableEntryType $type
 * @property string $amount bertanda: + kasbon, − pelunasan/void/retur
 * @property string $balance_after
 * @property string|null $payment_method_id
 * @property string|null $cash_movement_id
 * @property string|null $user_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
final class ReceivableEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['member_id', 'outlet_id', 'order_id', 'payment_id', 'type', 'amount', 'balance_after', 'payment_method_id', 'cash_movement_id', 'user_id', 'note'];

    protected function casts(): array
    {
        return [
            'type' => ReceivableEntryType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
