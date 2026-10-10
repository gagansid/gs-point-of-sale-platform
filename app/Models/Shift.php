<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string $opened_by
 * @property string|null $closed_by
 * @property string $opening_cash
 * @property string|null $expected_cash
 * @property string|null $actual_cash
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

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
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
}
