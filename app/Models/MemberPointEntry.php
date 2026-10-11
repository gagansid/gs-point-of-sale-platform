<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointEntryType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu mutasi poin member (tabel member_point_ledger, ADR 0013 / Q62). Hanya ditambah, tidak pernah diubah —
 * kecuali `remaining` pada kredit (FIFO pemakaian & kedaluwarsa). Ditulis lewat App\Services\Member\PointLedger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $member_id
 * @property string|null $outlet_id
 * @property string|null $order_id
 * @property string|null $payment_id
 * @property PointEntryType $type
 * @property int $points
 * @property int $remaining
 * @property int $balance_after
 * @property CarbonImmutable|null $expires_at
 * @property string|null $reason
 * @property string|null $user_id
 * @property CarbonImmutable $created_at
 */
final class MemberPointEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'member_point_ledger';

    protected $fillable = ['member_id', 'outlet_id', 'order_id', 'payment_id', 'type', 'points', 'remaining', 'balance_after', 'expires_at', 'reason', 'user_id'];

    protected function casts(): array
    {
        return [
            'type' => PointEntryType::class,
            'points' => 'integer',
            'remaining' => 'integer',
            'balance_after' => 'integer',
            'expires_at' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
