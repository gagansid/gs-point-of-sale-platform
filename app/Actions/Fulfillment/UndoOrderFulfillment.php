<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment;

use App\Enums\ErrorCode;
use App\Enums\FulfillmentStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Urungkan langkah pengantaran terakhir dari toast (ADR 0027 §4): hanya oleh staf yang sama, dalam
 * {@see self::WINDOW_SECONDS} detik, dan hanya satu langkah (tahap sebelumnya dipulihkan beserta waktunya).
 */
final class UndoOrderFulfillment
{
    public const WINDOW_SECONDS = 10;

    /** @throws BusinessException FORBIDDEN, ORDER_ALREADY_CLOSED */
    public function handle(User $actor, Order $order): Order
    {
        if (! $actor->can('order.fulfill') || ! $actor->canAccessOutlet($order->outlet_id)) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        return DB::transaction(function () use ($actor, $order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $previous = $locked->fulfillment_previous;

            if ($previous === null || $locked->fulfillment_auto || $locked->fulfillment_updated_by !== $actor->id
                || $locked->fulfillment_updated_at === null || $locked->fulfillment_updated_at->lessThan(now()->subSeconds(self::WINDOW_SECONDS))) {
                throw BusinessException::of(ErrorCode::OrderAlreadyClosed, 'Langkah ini sudah tidak bisa diurungkan');
            }

            $locked->forceFill([
                'fulfillment_status' => $previous,
                'fulfillment_previous' => null,
                'prepared_at' => $previous->rank() >= FulfillmentStatus::Preparing->rank() ? $locked->prepared_at : null,
                'ready_at' => $previous->rank() >= FulfillmentStatus::Ready->rank() ? $locked->ready_at : null,
                'served_at' => null,
                'served_by' => null,
            ])->save();

            return $locked;
        });
    }
}
