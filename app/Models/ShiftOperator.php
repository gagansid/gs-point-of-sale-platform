<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siapa memegang kasir di sebuah shift, dari jam berapa sampai jam berapa (ADR 0017). Baris baru saat
 * operator berganti (login PIN / buka kunci di perangkat yang shift-nya terbuka). Ditulis lewat
 * App\Actions\Shift\RecordShiftOperator.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shift_id
 * @property string $user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
final class ShiftOperator extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['shift_id', 'user_id', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
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
}
