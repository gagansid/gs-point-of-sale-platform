<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VisibleOrders;
use App\Actions\Order\VoidOrder;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
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

    /** Detail order + form void. */
    public function show(string $order): View
    {
        $model = $this->visible($order);

        return view('cashier.orders.show', [
            'order' => $model->load(['items.options', 'payments.method', 'user:id,name']),
            'timezone' => CurrentOutlet::timezone(),
            'canVoidSelf' => self::user()->can('order.void'),
            'approvers' => self::user()->can('order.void') ? collect() : User::query()->where('is_active', true)->whereNotNull('pin')
                ->whereKeyNot(self::user()->id)->orderBy('name')->get()
                ->filter(fn (User $u): bool => $u->can('order.void') && $u->canAccessOutlet($model->outlet_id))->values(),
        ]);
    }

    /** Void (Q: alasan wajib; tanpa order.void → PIN approver, bukan diri sendiri). */
    public function void(Request $request, string $order, VoidOrder $void): RedirectResponse
    {
        abort_unless(self::user()->can('order.create'), 403);
        $model = $this->visible($order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'string', 'digits:6', 'required_with:approver_user_id'],
        ], [], ['reason' => 'alasan', 'approver_user_id' => 'approver', 'approver_pin' => 'PIN approver']);

        $void->handle(self::user(), $model, $data['reason'], CheckoutData::parseApproval($data));

        return redirect()->route('cashier.orders.show', $model->id)->with('status', 'Order berhasil di-void');
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

    /** Order yang boleh dilihat user di outlet browser ini; selain itu 404 (fail-closed). */
    private function visible(string $id): Order
    {
        return VisibleOrders::query(self::user())->findOrFail($id);
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
