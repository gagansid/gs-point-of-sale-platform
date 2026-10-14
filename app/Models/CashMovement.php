<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kas masuk/keluar laci selama shift (ADR 0017). Tidak bisa diubah/dihapus; koreksi dengan catatan lawan.
 * Ditulis lewat App\Actions\Shift\RecordCashMovement.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $shift_id
 * @property string $user_id
 * @property CashMovementType $type
 * @property CashMovementReason $reason
 * @property string $amount
 * @property string|null $note
 * @property string|null $approved_by
 * @property CarbonImmutable $created_at
 */
final class CashMovement extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'shift_id', 'user_id', 'type', 'reason', 'amount', 'note', 'approved_by'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'reason' => CashMovementReason::class,
            'amount' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
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
