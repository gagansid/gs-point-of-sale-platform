<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Data\OpenBillData;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Order\OrderNumberGenerator;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Simpan / ubah open bill (PUT /orders/{id}, dine-in). Upsert berdasarkan ID dari aplikasi.
 *
 * - Item dikirim lengkap dan menggantikan item lama; total dihitung ulang.
 * - Nomor order diberikan saat dibuat; stok BELUM dipotong (dipotong saat lunas).
 * - Order yang sudah selesai/void tidak bisa diubah (ORDER_ALREADY_CLOSED).
 * - Total baru tidak boleh lebih kecil dari yang sudah dibayar.
 */
final class SaveOpenBill
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly PriceOrder $priceOrder,
        private readonly OrderWriter $writer,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * @return array{order: Order, created: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, string $orderId, OpenBillData $data): array
    {
        $existing = Idempotency::existing(Order::class, $orderId);
        self::ensureOpen($existing);

        $shift = CheckoutOrder::openShiftOf($device);
        $outlet = Outlet::query()->findOrFail($existing->outlet_id ?? $shift->outlet_id);

        $lines = $this->resolveLines->handle($data->items);
        $priced = $this->priceOrder->handle($actor, $outlet, $lines, $data->discountType, $data->discountValue, $data->approval);
        $totals = $priced['totals'];

        try {
            $result = DB::transaction(function () use ($actor, $device, $shift, $outlet, $orderId, $data, $lines, $priced, $totals): array {
                $order = Order::query()->lockForUpdate()->find($orderId);
                self::ensureOpen($order);
                $created = $order === null;

                if ($order === null) {
                    $order = new Order([
                        'outlet_id' => $outlet->id,
                        'shift_id' => $shift->id,
                        'device_id' => $device->id,
                        'user_id' => $actor->id,
                    ]);
                    $order->id = $orderId;
                    $order->forceFill([
                        'order_number' => $this->numbers->next($outlet),
                        'status' => OrderStatus::Open,
                    ]);
                } elseif (Money::compare($order->paid_total, $totals->grandTotal) > 0) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'Total baru lebih kecil dari yang sudah dibayar', [
                        'items' => ['Total baru '.Money::format($totals->grandTotal).' lebih kecil dari yang sudah dibayar '.Money::format($order->paid_total)],
                    ]);
                }

                $order->fill([
                    'order_type' => $data->orderType,
                    'table_label' => $data->tableLabel,
                    'notes' => $data->notes,
                ]);
                $order->forceFill([
                    'discount_type' => $data->discountType,
                    'discount_value' => $data->discountType !== null ? $data->discountValue : null,
                    'approved_by' => $priced['approved_by'],
                ]);
                $this->writer->applyTotals($order, $totals, $outlet);
                $order->save();

                $this->writer->replaceItems($order, $lines, $totals);

                // Item dikurangi hingga pas dengan yang sudah dibayar → order langsung selesai
                if (Money::isPositive($order->paid_total) && Money::compare($order->paid_total, $order->grand_total) >= 0) {
                    $this->writer->complete($order, $shift, $actor);
                }

                return ['order' => $order, 'created' => $created];
            });
        } catch (UniqueConstraintViolationException) {
            // Pembuatan bersamaan dengan ID sama: data yang sudah tersimpan menang
            return ['order' => CheckoutOrder::load(Order::query()->findOrFail($orderId)), 'created' => false];
        }

        return ['order' => CheckoutOrder::load($result['order']), 'created' => $result['created']];
    }

    /** @throws BusinessException */
    private static function ensureOpen(?Order $order): void
    {
        if ($order !== null && $order->status !== OrderStatus::Open) {
            throw BusinessException::of(ErrorCode::OrderAlreadyClosed);
        }
    }
}
