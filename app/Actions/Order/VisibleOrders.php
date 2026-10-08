<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order yang boleh dilihat user (riwayat, detail, struk) — Q21:
 * - order.view_all: semua order; tanpa report.view (supervisor) hanya hari ini (tanggal lokal outlet);
 * - order.view_own: order di shift yang ia buka + semua open bill (agar bisa melanjutkan meja).
 */
final class VisibleOrders
{
    /** @return Builder<Order> */
    public static function query(User $user): Builder
    {
        $query = Order::query();

        if ($user->can('order.view_all')) {
            if (! $user->can('report.view')) {
                $today = CurrentOutlet::today();
                [$start, $end] = CurrentOutlet::utcRange($today, $today);
                $query->whereBetween('created_at', [$start, $end]);
            }

            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('status', OrderStatus::Open)
            ->orWhereIn('shift_id', Shift::query()->where('opened_by', $user->id)->select('id')));
    }

    public static function canView(User $user, Order $order): bool
    {
        return $order->tenant_id === $user->tenant_id
            && self::query($user)->whereKey($order->id)->exists();
    }
}
