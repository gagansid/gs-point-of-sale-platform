<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Member\RedeemPoints;
use App\Actions\Tenant\EnsureOwnerEmailVerified;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tambah pembayaran ke open bill atau pesanan meja yang sudah diterima (POST /orders/{id}/payments). Boleh bayar sebagian (split bill);
 * order selesai saat lunas: stok dipotong dan order pindah ke shift penerima uang (Q20).
 * ID pembayaran = idempotency key: kiriman ulang tidak mencatat pembayaran dua kali.
 */
final class AddPayment
{
    public function __construct(
        private readonly AllocatePayments $allocatePayments,
        private readonly OrderWriter $writer,
    ) {}

    /**
     * @param  list<array{id: string, payment_method_id: string, amount: string, tendered: string|null, reference: string|null}>  $payments
     * @return array{order: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, Order $order, array $payments, ?string $memberCode = null, ?string $memberPin = null): array
    {
        // Route binding hanya membatasi ke outlet yang dipegang user; pembayaran wajib di outlet perangkat
        CheckoutOrder::ensureAtOutlet($order, $device->outlet_id);

        $ids = array_column($payments, 'id');
        $existingOnOrder = Payment::query()->where('order_id', $order->id)->whereIn('id', $ids)->count();

        if ($ids !== [] && $existingOnOrder === count($ids)) {
            return ['order' => CheckoutOrder::load($order->refresh()), 'replayed' => true];
        }

        // Kiriman ulang pelunasan tagihan 0 (tanpa pembayaran) setelah order selesai
        if ($ids === [] && $order->status === OrderStatus::Completed && ! Money::isPositive($order->grand_total)) {
            return ['order' => CheckoutOrder::load($order->refresh()), 'replayed' => true];
        }

        app(EnsureOwnerEmailVerified::class)->handle($actor->tenant);

        PaymentIds::ensureUnused($payments);

        self::ensurePayable($order);

        $shift = CheckoutOrder::openShiftOf($device);

        // Bayar dengan poin (ADR 0013): bukti pemilik diverifikasi di luar transaksi
        $categories = PaymentMethod::query()->whereIn('id', array_column($payments, 'payment_method_id'))->pluck('category', 'id');
        app(RedeemPoints::class)->check(
            array_map(fn (array $p): array => ['category' => $categories[$p['payment_method_id']] ?? PaymentCategory::Cash, 'amount' => $p['amount']], $payments),
            $order->member_id, $order->outlet_id, $memberCode, $memberPin,
        );

        $order = DB::transaction(function () use ($actor, $shift, $order, $payments): Order {
            // Kunci order: dua pembayaran bersamaan tidak bisa melebihi sisa tagihan
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            self::ensurePayable($locked);

            $remaining = Money::sub($locked->grand_total, $locked->paid_total);

            // Tanpa pembayaran hanya untuk melunasi tagihan yang sisanya 0 (mis. diskon 100%)
            if ($payments === [] && Money::isPositive($remaining)) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Pembayaran wajib diisi', [
                    'payments' => ['Sisa tagihan '.Money::format($remaining)],
                    'remaining' => $remaining,
                ]);
            }
            $allocated = $this->allocatePayments->handle($payments, $remaining, $locked->outlet_id);

            $this->writer->addPayments($locked, $allocated, $actor);
            app(RedeemPoints::class)->handle($locked, $allocated);

            if (Money::compare($locked->paid_total, $locked->grand_total) >= 0) {
                $this->writer->complete($locked, $shift, $actor);
            } else {
                $locked->save();
            }

            return $locked;
        });

        return ['order' => CheckoutOrder::load($order), 'replayed' => false];
    }

    /**
     * Bisa dibayar: open bill atau pesanan meja yang sudah diterima (ADR 0014). Pesanan meja kedaluwarsa → ORDER_EXPIRED.
     *
     * @throws BusinessException
     */
    private static function ensurePayable(Order $order): void
    {
        if ($order->status === OrderStatus::Expired) {
            throw BusinessException::of(ErrorCode::OrderExpired, 'Pesanan meja sudah kedaluwarsa karena tidak dibayar');
        }

        if (! $order->status->isPayable()) {
            throw BusinessException::of(ErrorCode::OrderAlreadyClosed);
        }
    }
}
