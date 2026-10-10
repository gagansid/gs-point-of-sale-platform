<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order. ID dibuat aplikasi (idempotency key). Nominal SELALU dari OrderCalculator.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $shift_id
 * @property string|null $device_id
 * @property string $user_id
 * @property string $order_number
 * @property OrderType $order_type
 * @property string|null $table_label
 * @property OrderStatus $status
 * @property string|null $notes
 * @property DiscountType|null $discount_type
 * @property string|null $discount_value
 * @property string $order_discount
 * @property string $subtotal
 * @property string $discount_total
 * @property string $service_total
 * @property string $tax_total
 * @property string $rounding
 * @property string $grand_total
 * @property string $paid_total
 * @property string $change_total
 * @property string $service_rate
 * @property string $tax_rate
 * @property bool $tax_inclusive
 * @property string|null $void_reason
 * @property string|null $voided_by
 * @property string|null $void_approved_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $voided_at
 */
final class Order extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids;

    /** Default sama dengan database agar order baru di memori sudah punya nilai pembayaran. */
    protected $attributes = [
        'paid_total' => '0.00',
        'change_total' => '0.00',
        'order_discount' => '0.00',
    ];

    /** Nominal tidak fillable: hanya diisi Action dari hasil OrderCalculator (forceFill). */
    protected $fillable = ['outlet_id', 'shift_id', 'device_id', 'user_id', 'order_type', 'table_label', 'notes'];

    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'order_discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'service_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'rounding' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_total' => 'decimal:2',
            'service_rate' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
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
