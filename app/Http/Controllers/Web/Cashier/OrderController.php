<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Order\VisibleOrders;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    /** Struk (cetak/cetak ulang): order yang boleh dilihat + order.reprint; tenant/outlet lain 404. */
    public function receipt(Request $request, string $order): Response
    {
        $model = Order::query()->with(['items.options', 'payments.method', 'user', 'outlet'])->findOrFail($order);
        Gate::forUser(self::user())->authorize('reprint', $model);

        return response()->view('receipts.print', [
            'receipt' => (new ReceiptResource($model))->resolve($request),
            'autoprint' => $request->boolean('print'),
        ])->header('Cache-Control', 'no-store, private');
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
