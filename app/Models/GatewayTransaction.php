<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percobaan bayar online untuk order (ADR 0014, Q72): Snap (redirect) atau QRIS Core API yang tampil di halaman meja
 * (ADR 0027). `id` = order_id di Midtrans. Lunas hanya dari webhook terverifikasi (tanda tangan + cek ulang status ke API +
 * nominal cocok).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $amount
 * @property string $status pending / settlement / expire / cancel / deny / failure / refund_required
 * @property string $method snap / qris
 * @property string|null $qr_string isi QR QRIS (dirender server dengan QrSvg)
 * @property CarbonImmutable|null $expires_at batas QR QRIS
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

    public const SNAP = 'snap';

    public const QRIS = 'qris';

    protected $fillable = ['order_id', 'amount', 'status', 'method', 'snap_token', 'redirect_url', 'qr_string', 'expires_at', 'transaction_id', 'payment_type'];

    protected $attributes = ['method' => self::SNAP];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /** QR QRIS masih bisa dipindai. */
    public function isQrActive(?CarbonImmutable $now = null): bool
    {
        return $this->method === self::QRIS && $this->status === self::PENDING && $this->qr_string !== null
            && $this->expires_at !== null && $this->expires_at->greaterThan($now ?? CarbonImmutable::now());
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
