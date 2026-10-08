<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batalkan order (POST /orders/{id}/void) — SPEC Shift & void:
 * - hanya order di shift yang masih terbuka, alasan wajib;
 * - kasir butuh PIN owner/manager/supervisor (order.void), tidak boleh approve diri sendiri;
 * - stok order selesai dikembalikan, pembayaran ikut di-void;
 * - void kedua kali mengembalikan order yang sama (idempotent).
 */
final class VoidOrder
{
    public function __construct(
        private readonly VerifyApproval $verifyApproval,
        private readonly OrderWriter $writer,
    ) {}

    /**
     * @return array{order: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Order $order, string $reason, ?ApprovalData $approval): array
    {
        if ($order->status === OrderStatus::Voided) {
            return ['order' => CheckoutOrder::load($order), 'replayed' => true];
        }

        $this->ensureShiftOpen($order);

        // Di luar transaksi: percobaan PIN salah harus tetap tercatat untuk penguncian
        $approverId = $this->verifyApproval->handle($actor, 'order.void', $approval);

        $result = DB::transaction(function () use ($actor, $order, $reason, $approverId): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === OrderStatus::Voided) {
                return ['order' => $locked, 'replayed' => true];
            }

            $this->ensureShiftOpen($locked);

            if ($locked->status === OrderStatus::Completed) {
                $this->writer->moveStock($locked, $actor, StockMovementType::VoidReturn);
            }

            $locked->payments()->update(['status' => PaymentStatus::Voided]);
            $locked->forceFill([
                'status' => OrderStatus::Voided,
                'void_reason' => trim($reason),
                'voided_by' => $actor->id,
                'void_approved_by' => $approverId,
                'voided_at' => now(),
            ])->save();

            return ['order' => $locked, 'replayed' => false];
        });

        if (! $result['replayed']) {
            Log::warning('Order di-void', [
                'order_id' => $order->id,
                'user_id' => $actor->id,
                'approved_by' => $approverId,
                'grand_total' => $order->grand_total,
            ]);
        }

        return ['order' => CheckoutOrder::load($result['order']), 'replayed' => $result['replayed']];
    }

    /** @throws BusinessException */
    private function ensureShiftOpen(Order $order): void
    {
        $shift = Shift::query()->find($order->shift_id);

        if ($shift === null || ! $shift->isOpen()) {
            throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift transaksi ini sudah ditutup; void tidak bisa dilakukan');
        }
    }
}
