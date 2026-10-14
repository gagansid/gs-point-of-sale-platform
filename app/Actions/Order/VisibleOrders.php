<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\ActorDevice;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order yang boleh dilihat user (riwayat, detail, struk) — Q21:
 * - order.view_all: semua order; tanpa report.view (supervisor) hanya hari ini (tanggal lokal outlet);
 * - order.view_own: order di shift yang ia buka, di shift yang sedang terbuka di perangkatnya (shift bersama,
 *   ADR 0017) + semua open bill & pesanan meja menunggu bayar.
 */
final class VisibleOrders
{
    /** @return Builder<Order> */
    public static function query(User $user): Builder
    {
        // Outlet perangkat (API) atau pilihan pemilih outlet sidebar (dashboard), ADR 0010
        $query = CurrentOutlet::scope(Order::query());

        if ($user->can('order.view_all')) {
            if (! $user->can('report.view')) {
                $today = CurrentOutlet::today();
                [$start, $end] = CurrentOutlet::utcRange($today, $today);
                $query->whereBetween('created_at', [$start, $end]);
            }

            return $query;
        }

        $deviceId = ActorDevice::id($user);

        return $query->where(fn (Builder $q) => $q
            // Open bill & pesanan meja menunggu bayar bisa dilunasi kasir mana pun (ADR 0014)
            ->whereIn('status', [OrderStatus::Open, OrderStatus::AwaitingPayment])
            ->orWhereIn('shift_id', Shift::query()->where('opened_by', $user->id)->select('id'))
            // Ganti kasir tanpa hitung: semua order shift yang sedang berjalan di perangkat ini
            ->when($deviceId !== null, fn (Builder $q) => $q->orWhereIn('shift_id', Shift::query()->where('open_device_key', $deviceId)->select('id'))));
    }

    public static function canView(User $user, Order $order): bool
    {
        return $order->tenant_id === $user->tenant_id
            && self::query($user)->whereKey($order->id)->exists();
    }
}
