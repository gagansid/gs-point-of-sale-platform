<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SelfOrderStatus;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pesanan dari HP lewat QR meja (ADR 0014, Q68). Ditulis lewat SubmitSelfOrder; diterima kasir → order
 * (status awaiting_payment) dibuat dan ditautkan lewat `order_id`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $dining_table_id
 * @property string|null $member_id
 * @property string $customer_name
 * @property list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null}> $items
 * @property string|null $notes
 * @property string|null $voucher_code
 * @property string $estimated_total
 * @property SelfOrderStatus $status
 * @property string|null $reject_reason
 * @property string|null $order_id
 * @property string|null $handled_by
 * @property CarbonImmutable|null $handled_at
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 */
final class SelfOrder extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids;

    protected $fillable = ['outlet_id', 'dining_table_id', 'member_id', 'customer_name', 'items', 'notes', 'voucher_code', 'estimated_total', 'ip_address'];

    protected $attributes = ['status' => 'pending', 'estimated_total' => '0.00'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'estimated_total' => 'decimal:2',
            'status' => SelfOrderStatus::class,
            'handled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Kasir yang menerima/menolak.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function itemCount(): int
    {
        return (int) array_sum(array_column($this->items, 'qty'));
    }
}
