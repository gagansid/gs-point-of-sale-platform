<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Auth\PinApprovers;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\RefundData;
use App\Actions\Order\RefundableOrders;
use App\Actions\Order\RefundOrder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\RefundOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Retur sebagian di kasir web (ADR 0018): cari nomor transaksi di outlet browser ini (selesai, dalam batas hari),
 * pilih item & qty, alasan, cara pengembalian; kasir butuh PIN approver.
 */
final class RefundController extends Controller
{
    public function search(Request $request): View|RedirectResponse
    {
        $number = trim($request->string('number')->toString());

        if ($number !== '') {
            $order = RefundableOrders::find(mb_substr($number, 0, 40), WebCashier::device()->outlet_id);

            if ($order === null) {
                throw ValidationException::withMessages(['number' => 'Transaksi tidak ditemukan, belum selesai, atau lewat '.RefundableOrders::maxDays(WebCashier::device()->outlet_id).' hari.']);
            }

            return redirect()->route('cashier.refunds.create', $order->id);
        }

        return view('cashier.refunds.search', ['maxDays' => RefundableOrders::maxDays(WebCashier::device()->outlet_id)]);
    }

    public function create(string $order): View
    {
        $model = $this->refundable($order);

        return view('cashier.refunds.create', [
            'order' => CheckoutOrder::load($model)->load(['refunds.user:id,name']),
            'timezone' => CurrentOutlet::timezone(),
            'approvers' => PinApprovers::for(self::user(), 'order.refund', $model->outlet_id),
            'refundId' => (string) Str::uuid7(),
        ]);
    }

    public function store(Request $request, string $order, RefundOrder $refund): RedirectResponse
    {
        $model = $this->refundable($order);
        $data = $request->validate(['id' => ['required', 'uuid'], ...RefundOrderRequest::fieldRules()], [], RefundOrderRequest::fieldAttributes());

        $result = $refund->handle(self::user(), WebCashier::device(), $model, RefundData::fromArray($data), CheckoutData::parseApproval($data));

        return redirect()->route('cashier.refunds.create', $model->id)
            ->with('status', $result['replayed'] ? 'Retur sudah tercatat' : 'Retur '.Money::format($result['refund']->payout_amount).' dicatat');
    }

    /** Order selesai di outlet browser ini; selain itu 404. Batas hari & sisa item dicek RefundOrder. */
    private function refundable(string $id): Order
    {
        abort_unless(Str::isUuid($id), 404);

        return Order::query()->whereKey($id)->where('outlet_id', WebCashier::device()->outlet_id)
            ->where('status', OrderStatus::Completed)->firstOrFail();
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
