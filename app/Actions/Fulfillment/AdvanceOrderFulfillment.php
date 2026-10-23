<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment;

use App\Enums\ErrorCode;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staf memajukan pesanan meja yang sudah lunas: Antre → Disiapkan → Siap diantar → Diantar (ADR 0027 §4, izin
 * `order.fulfill`, outlet yang ditugaskan). Hanya maju (boleh melompat ke depan, mis. Antre → Diantar), tidak pernah
 * mundur; meminta tahap yang sudah tercapai = tanpa perubahan (idempotent, aman untuk ketuk ganda & dua layar).
 * Dipakai kasir web /cashier/queue, kasir Pesanan meja, dashboard Antrean pesanan, dan API Flutter.
 */
final class AdvanceOrderFulfillment
{
    /** @throws BusinessException FORBIDDEN, NOT_FOUND, ORDER_ALREADY_CLOSED, VALIDATION_ERROR */
    public function handle(User $actor, Order $order, FulfillmentStatus $target): Order
    {
        if (! $actor->can('order.fulfill')) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        if (! $actor->canAccessOutlet($order->outlet_id) || $order->channel !== OrderChannel::SelfOrder) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        if ($target === FulfillmentStatus::Queued || $target === FulfillmentStatus::AutoCompleted) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tahap tidak bisa dipilih', ['status' => ['Pilih Disiapkan, Siap diantar, atau Diantar']]);
        }

        return DB::transaction(function () use ($actor, $order, $target): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = $locked->fulfillment_status;

            if ($locked->status !== OrderStatus::Completed || $current === null) {
                throw BusinessException::of(ErrorCode::OrderAlreadyClosed, 'Pesanan ini belum lunas atau sudah dibatalkan');
            }

            // Sudah di tahap itu atau lebih jauh: tidak mundur, tidak error (dua layar memajukan pesanan yang sama)
            if ($target->rank() <= $current->rank()) {
                return $locked;
            }

            $now = now();
            $locked->forceFill([
                'fulfillment_status' => $target,
                'fulfillment_previous' => $current,
                'fulfillment_updated_by' => $actor->id,
                'fulfillment_updated_at' => $now,
                // Tahap yang dilompati tetap diberi waktu agar lini waktu HP lengkap
                'prepared_at' => $locked->prepared_at ?? ($target->rank() >= FulfillmentStatus::Preparing->rank() ? $now : null),
                'ready_at' => $locked->ready_at ?? ($target->rank() >= FulfillmentStatus::Ready->rank() ? $now : null),
                'served_at' => $target === FulfillmentStatus::Served ? $now : $locked->served_at,
                'served_by' => $target === FulfillmentStatus::Served ? $actor->id : $locked->served_by,
            ])->save();

            return $locked;
        });
    }
}
