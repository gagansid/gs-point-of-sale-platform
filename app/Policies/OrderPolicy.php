<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Order\VisibleOrders;
use App\Models\Order;
use App\Models\User;

/**
 * Melihat order (detail & struk). Aturan visibilitas di VisibleOrders (Q21).
 */
final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('order.view_own') || $user->can('order.view_all');
    }

    public function view(User $user, Order $order): bool
    {
        return VisibleOrders::canView($user, $order);
    }

    public function reprint(User $user, Order $order): bool
    {
        return $user->can('order.reprint') && VisibleOrders::canView($user, $order);
    }
}
