<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StampEntryType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu mutasi stempel member per program (ADR 0013, Q65). Hanya ditambah; ditulis lewat
 * App\Services\Member\StampCards dengan baris member terkunci.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $member_id
 * @property string $stamp_program_id
 * @property string|null $outlet_id
 * @property string|null $order_id
 * @property StampEntryType $type
 * @property int $change
 * @property int $balance_after
 * @property string|null $reason
 * @property string|null $user_id
 * @property CarbonImmutable $created_at
 */
final class MemberStamp extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['member_id', 'stamp_program_id', 'outlet_id', 'order_id', 'type', 'change', 'balance_after', 'reason', 'user_id'];

    protected function casts(): array
    {
        return [
            'type' => StampEntryType::class,
            'change' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StampProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(StampProgram::class, 'stamp_program_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
