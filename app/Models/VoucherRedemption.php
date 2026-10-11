<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemakaian voucher di satu order (ADR 0013, Q66): status `used` saat order selesai, `voided` saat order di-void
 * (kuota kembali).
 *
 * @property string $id
 * @property string $voucher_id
 * @property string|null $member_id
 * @property string $order_id
 * @property string|null $outlet_id
 * @property string $amount
 * @property string $status used / voided
 */
final class VoucherRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    public const USED = 'used';

    public const VOIDED = 'voided';

    protected $fillable = ['voucher_id', 'member_id', 'order_id', 'outlet_id', 'amount', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
