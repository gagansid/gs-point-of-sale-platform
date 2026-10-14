<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpeningCashSource;
use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift milik perangkat (ADR 0017): dipakai bergantian oleh operator yang berganti lewat kunci layar + PIN.
 * opened_by hanya mencatat pembuka; operator tercatat di shift_operators.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string $opened_by
 * @property string|null $closed_by
 * @property string $opening_cash
 * @property OpeningCashSource $opening_cash_source
 * @property string|null $carried_cash carry-over sebelum dikoreksi (ADR 0017)
 * @property string|null $previous_shift_id
 * @property string|null $opening_cash_note
 * @property string|null $opening_cash_adjusted_by
 * @property string|null $opening_cash_approved_by
 * @property CarbonImmutable|null $opening_cash_adjusted_at
 * @property string|null $expected_cash
 * @property string|null $actual_cash
 * @property string|null $cash_deposited disetor/diambil saat tutup
 * @property string|null $closing_float ditinggal di laci = kas awal shift berikutnya di perangkat ini
 * @property string|null $difference
 * @property ShiftStatus $status
 * @property string|null $close_note
 * @property string|null $open_device_key
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 */
final class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use BelongsToOutlet, BelongsToTenant, HasFactory, HasUuids, RecordsAuthor;

    protected $fillable = ['outlet_id', 'device_id', 'opened_by', 'opening_cash', 'status', 'opened_at'];

    protected $attributes = ['opening_cash_source' => 'manual'];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'opening_cash_source' => OpeningCashSource::class,
            'carried_cash' => 'decimal:2',
            'opening_cash_adjusted_at' => 'immutable_datetime',
            'cash_deposited' => 'decimal:2',
            'closing_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'status' => ShiftStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ShiftOperator, $this> */
    public function operators(): HasMany
    {
        return $this->hasMany(ShiftOperator::class)->orderBy('started_at');
    }

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function openingCashAdjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opening_cash_adjusted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function openingCashApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opening_cash_approved_by');
    }

    /** Pembuka shift atau pernah menjadi operatornya (ganti kasir, ADR 0017). */
    public function wasOperatedBy(User $user): bool
    {
        return $this->opened_by === $user->id
            || $this->operators()->where('user_id', $user->id)->exists();
    }
}
