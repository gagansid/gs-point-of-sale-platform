<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Cari transaksi untuk diretur (ADR 0018): nomor order di outlet perangkat, selesai, dalam batas hari retur.
 * Sengaja tidak memakai VisibleOrders: kasir perlu meretur transaksi shift lain; aksinya tetap butuh izin/PIN.
 */
final class RefundableOrders
{
    public static function find(string $orderNumber, string $outletId): ?Order
    {
        return Order::query()
            ->where('outlet_id', $outletId)
            ->where('order_number', mb_strtoupper(trim($orderNumber)))
            ->where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', now()->subDays((int) config('pos.refund.max_days')))
            ->first();
    }
}
