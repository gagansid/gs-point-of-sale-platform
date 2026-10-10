<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Tenant\EnsureOwnerEmailVerified;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tambah pembayaran ke open bill (POST /orders/{id}/payments). Boleh bayar sebagian (split bill);
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
    public function handle(User $actor, Device $device, Order $order, array $payments): array
    {
        $ids = array_column($payments, 'id');
        $existingOnOrder = Payment::query()->where('order_id', $order->id)->whereIn('id', $ids)->count();

        if ($existingOnOrder === count($ids)) {
            return ['order' => CheckoutOrder::load($order->refresh()), 'replayed' => true];
        }

        app(EnsureOwnerEmailVerified::class)->handle($actor->tenant);

        PaymentIds::ensureUnused($payments);

        if ($order->status !== OrderStatus::Open) {
            throw BusinessException::of(ErrorCode::OrderAlreadyClosed);
        }

        $shift = CheckoutOrder::openShiftOf($device);

        $order = DB::transaction(function () use ($actor, $shift, $order, $payments): Order {
            // Kunci order: dua pembayaran bersamaan tidak bisa melebihi sisa tagihan
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Open) {
                throw BusinessException::of(ErrorCode::OrderAlreadyClosed);
            }

            $remaining = Money::sub($locked->grand_total, $locked->paid_total);
            $allocated = $this->allocatePayments->handle($payments, $remaining);

            $this->writer->addPayments($locked, $allocated, $actor);

            if (Money::compare($locked->paid_total, $locked->grand_total) >= 0) {
                $this->writer->complete($locked, $shift, $actor);
            } else {
                $locked->save();
            }

            return $locked;
        });

        return ['order' => CheckoutOrder::load($order), 'replayed' => false];
    }
}
