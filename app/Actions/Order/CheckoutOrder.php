<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Data\CheckoutData;
use App\Actions\Tenant\EnsureOwnerEmailVerified;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Services\Order\OrderNumberGenerator;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Buat order + bayar sekaligus (POST /checkout, alur takeaway/retail).
 *
 * Server sumber kebenaran: harga dari katalog, total dari OrderCalculator (lewat PriceOrder),
 * nominal kiriman aplikasi hanya dipakai sebagai uang yang dibayarkan. ID order & payment dari
 * aplikasi adalah idempotency key: request ganda mengembalikan order yang sama tanpa memotong
 * stok lagi.
 */
final class CheckoutOrder
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly PriceOrder $priceOrder,
        private readonly AllocatePayments $allocatePayments,
        private readonly OrderWriter $writer,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * @return array{order: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, CheckoutData $data): array
    {
        if (($existing = Idempotency::existing(Order::class, $data->id)) !== null) {
            return ['order' => self::load($existing), 'replayed' => true];
        }

        // Setelah cek idempotensi: kiriman ulang order lama tetap mendapat data lama
        app(EnsureOwnerEmailVerified::class)->handle($actor->tenant);

        PaymentIds::ensureUnused($data->payments);

        $shift = self::openShiftOf($device);
        $outlet = Outlet::query()->findOrFail($shift->outlet_id);

        $lines = $this->resolveLines->handle($data->items);
        $priced = $this->priceOrder->handle($actor, $outlet, $lines, $data->discountType, $data->discountValue, $data->approval);
        $totals = $priced['totals'];
        $payments = $this->allocatePayments->handle($data->payments, $totals->grandTotal);

        $paid = Money::add(...array_column($payments, 'amount'));
        if (Money::compare($paid, $totals->grandTotal) < 0) {
            $remaining = Money::sub($totals->grandTotal, $paid);
            throw BusinessException::of(ErrorCode::ValidationError, 'Pembayaran kurang', [
                'payments' => ['Pembayaran kurang '.Money::format($remaining)],
                'remaining' => $remaining,
            ]);
        }

        try {
            $order = DB::transaction(function () use ($actor, $device, $shift, $outlet, $data, $lines, $priced, $totals, $payments): Order {
                $order = new Order([
                    'outlet_id' => $outlet->id,
                    'shift_id' => $shift->id,
                    'device_id' => $device->id,
                    'user_id' => $actor->id,
                    'order_type' => $data->orderType,
                    'table_label' => $data->tableLabel,
                    'notes' => $data->notes,
                ]);
                $order->id = $data->id;
                $order->forceFill([
                    'order_number' => $this->numbers->next($outlet),
                    'status' => OrderStatus::Open,
                    'discount_type' => $data->discountType,
                    'discount_value' => $data->discountType !== null ? $data->discountValue : null,
                    'approved_by' => $priced['approved_by'],
                ]);
                $this->writer->applyTotals($order, $totals, $outlet);
                $order->save();

                $this->writer->replaceItems($order, $lines, $totals);
                $this->writer->addPayments($order, $payments, $actor);
                $this->writer->complete($order, $shift, $actor);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // Request kembar lolos pengecekan awal bersamaan; primary key menjadi penjaga terakhir
            return ['order' => self::load(Order::query()->findOrFail($data->id)), 'replayed' => true];
        }

        return ['order' => self::load($order), 'replayed' => false];
    }

    /** @throws BusinessException */
    public static function openShiftOf(Device $device): Shift
    {
        return Shift::query()->where('open_device_key', $device->id)->first()
            ?? throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift belum dibuka di perangkat ini');
    }

    public static function load(Order $order): Order
    {
        return $order->load(['items.options', 'payments.method']);
    }
}
