<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Order\VisibleOrders;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Riwayat transaksi kasir web: order shift terbuka browser ini yang boleh dilihat user (Q21). */
final class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $shift = ShiftController::openShift($request);

        $orders = $shift === null ? collect() : VisibleOrders::query(self::user())
            ->where('shift_id', $shift->id)
            ->with('user:id,name')
            ->latest()
            ->limit(200)
            ->get();

        return view('cashier.orders.index', ['shift' => $shift, 'orders' => $orders, 'timezone' => CurrentOutlet::timezone()]);
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
