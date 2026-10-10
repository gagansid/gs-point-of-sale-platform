<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Status satu metode bayar di satu outlet (tabel outlet_payment_method, ADR 0011 / Q44). Tanpa baris = aktif.
 * Metode dipakai di outlet bila aktif di bisnis (payment_methods.is_active) dan di sini.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $payment_method_id
 * @property bool $is_active
 */
final class OutletPaymentMethod extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'outlet_payment_method';

    protected $fillable = ['outlet_id', 'payment_method_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
