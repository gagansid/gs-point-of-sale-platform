<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran. ID dibuat aplikasi (idempotency key).
 * amount = nominal yang dipakai membayar; tendered = uang diterima; change = kembalian (tunai).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $payment_method_id
 * @property string $user_id
 * @property PaymentCategory $category
 * @property string $amount
 * @property string $tendered
 * @property string $change
 * @property string|null $reference
 * @property PaymentStatus $status
 */
final class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['order_id', 'payment_method_id', 'user_id', 'category', 'amount', 'tendered', 'change', 'reference', 'status'];

    protected function casts(): array
    {
        return [
            'category' => PaymentCategory::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'change' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id')->withTrashed();
    }
}
