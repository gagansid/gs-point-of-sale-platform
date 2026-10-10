<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\OutletPaymentMethod;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Mengaktifkan/menonaktifkan metode bayar di satu outlet (ADR 0011 / Q44, permission payment_method.manage).
 * Metode dipakai di outlet bila aktif di bisnis dan di outlet. Tunai selalu aktif (kembalian hanya tunai).
 */
final class SetPaymentMethodAtOutlet
{
    /**
     * @throws BusinessException
     */
    public function handle(PaymentMethod $method, Outlet $outlet, bool $active): void
    {
        if (! $active && $method->category === PaymentCategory::Cash) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tunai tidak bisa dinonaktifkan', [
                'is_active' => ['Tunai tidak bisa dinonaktifkan: kembalian hanya untuk pembayaran tunai'],
            ]);
        }

        DB::transaction(fn () => OutletPaymentMethod::query()->updateOrCreate(
            ['outlet_id' => $outlet->id, 'payment_method_id' => $method->id],
            ['is_active' => $active],
        ));
    }
}
