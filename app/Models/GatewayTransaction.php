<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percobaan bayar online (Snap Midtrans) untuk order (ADR 0014, Q72). `id` = order_id di Midtrans. Lunas hanya
 * dari webhook terverifikasi (tanda tangan + cek ulang status ke API + nominal cocok).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $amount
 * @property string $status pending / settlement / expire / cancel / deny / failure / refund_required
 * @property string|null $snap_token
 * @property string|null $redirect_url
 * @property string|null $transaction_id
 * @property string|null $payment_type
 * @property string|null $payment_id
 * @property CarbonImmutable|null $settled_at
 */
final class GatewayTransaction extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const SETTLEMENT = 'settlement';

    public const REFUND_REQUIRED = 'refund_required';

    protected $fillable = ['order_id', 'amount', 'status', 'snap_token', 'redirect_url', 'transaction_id', 'payment_type'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
