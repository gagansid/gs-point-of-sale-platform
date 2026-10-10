<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Metode pembayaran (permission payment_method.manage). Setiap bisnis punya satu metode
 * per kategori (bawaan); yang bisa diubah: nama, wajib nomor referensi, aktif, urutan tampil di kasir.
 * Tunai tidak bisa dinonaktifkan: kembalian hanya berlaku untuk tunai.
 */
final class UpdatePaymentMethod
{
    /**
     * @param  array{name?: string, requires_reference?: bool, is_active?: bool, sort_order?: int}  $changes
     *
     * @throws BusinessException
     */
    public function handle(PaymentMethod $method, array $changes): PaymentMethod
    {
        if (($changes['is_active'] ?? true) === false && $method->category === PaymentCategory::Cash) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tunai tidak bisa dinonaktifkan', [
                'is_active' => ['Tunai tidak bisa dinonaktifkan: kembalian hanya untuk pembayaran tunai'],
            ]);
        }

        DB::transaction(fn () => $method->fill(array_intersect_key($changes, array_flip(['name', 'requires_reference', 'is_active', 'sort_order'])))->save());

        return $method;
    }
}
