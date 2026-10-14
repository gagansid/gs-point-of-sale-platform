<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Money;
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
 * @property string|null $member_id member terpasang (ADR 0013)
 * @property string|null $member_name snapshot nama member saat transaksi
 * @property string|null $member_tier snapshot nama tier yang diskonnya dipakai (M5)
 * @property string $stamp_free_total potongan gratis stempel (M6)
 * @property list<array{stamp_program_id: string, name: string, product_id: string, product_name: string, amount: string}>|null $stamp_redemptions
 * @property int $stamps_earned
 * @property string|null $voucher_id voucher dipakai (M7)
 * @property string|null $voucher_code snapshot kode / nama voucher
 * @property string $voucher_discount
 * @property OrderChannel $channel pos / self_order (ADR 0014)
 * @property string|null $dining_table_id meja self-order
 * @property CarbonImmutable|null $accepted_at self-order diterima kasir
 * @property bool $refund_required dibayar online lalu di-void: refund manual (Q73)
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
 * @property string $refunded_total nilai yang sudah diretur (ADR 0018)
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
    use BelongsToOutlet, BelongsToTenant, HasUuids, RecordsAuthor;

    /** Default sama dengan database agar order baru di memori sudah punya nilai pembayaran. */
    protected $attributes = [
        'points_earned' => 0,
        'channel' => 'pos',
        'stamps_earned' => 0,
        'stamp_free_total' => '0.00',
        'voucher_discount' => '0.00',
        'paid_total' => '0.00',
        'refunded_total' => '0.00',
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
            'channel' => OrderChannel::class,
            'accepted_at' => 'immutable_datetime',
            'refund_required' => 'boolean',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'order_discount' => 'decimal:2',
            'stamp_free_total' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'stamp_redemptions' => 'array',
            'stamps_earned' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'service_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'rounding' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
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

    /** @return HasMany<OrderRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class)->orderBy('created_at');
    }

    /** Seluruh nilai order sudah diretur (ADR 0018). */
    public function isFullyRefunded(): bool
    {
        return Money::isPositive($this->grand_total) ? Money::compare($this->refunded_total, $this->grand_total) >= 0 : false;
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pelaku void.
     *
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Approver PIN void.
     *
     * @return BelongsTo<User, $this>
     */
    public function voidApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_approved_by');
    }

    /**
     * Approver PIN diskon di atas batas.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
