<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Metode pembayaran (permission payment_method.manage). Setiap bisnis punya satu metode
 * per kategori (bawaan); yang bisa diubah: nama, wajib nomor referensi, aktif, urutan tampil di kasir.
 * Tunai tidak bisa dinonaktifkan: kembalian hanya berlaku untuk tunai.
 * active_outlet_ids: outlet aktif tempat metode dipakai (ADR 0011 / Q44); outlet aktif yang tidak dicentang
 * dinonaktifkan, outlet nonaktif tidak diubah. Tidak dikirim = tidak diubah.
 */
final class UpdatePaymentMethod
{
    public function __construct(private readonly SetPaymentMethodAtOutlet $atOutlet) {}

    /**
     * @param  array{name?: string, requires_reference?: bool, is_active?: bool, sort_order?: int, active_outlet_ids?: list<string>}  $changes
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

        DB::transaction(function () use ($method, $changes): void {
            $method->fill(array_intersect_key($changes, array_flip(['name', 'requires_reference', 'is_active', 'sort_order'])))->save();

            if (isset($changes['active_outlet_ids']) && $method->category !== PaymentCategory::Cash) {
                foreach (Outlet::query()->active()->get() as $outlet) {
                    $this->atOutlet->handle($method, $outlet, in_array($outlet->id, $changes['active_outlet_ids'], true));
                }
            }
        });

        return $method;
    }
}
