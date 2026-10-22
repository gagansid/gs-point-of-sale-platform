<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Auth\PinApprovers;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\MergeOpenBills;
use App\Actions\Order\SplitOpenBill;
use App\Actions\Order\VisibleOrders;
use App\Actions\Order\VoidOrder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\SplitOpenBillRequest;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use App\Support\WebCashier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Riwayat transaksi kasir web: order shift terbuka browser ini yang boleh dilihat user (Q21). */
final class OrderController extends Controller
{
    /** Filter status Riwayat → status order. */
    private const FILTERS = [
        'completed' => [OrderStatus::Completed],
        'unpaid' => [OrderStatus::Open, OrderStatus::AwaitingPayment],
        'voided' => [OrderStatus::Voided],
    ];

    public function index(Request $request): View
    {
        $shift = ShiftController::openShift($request);
        $search = mb_substr(trim($request->string('q')->toString()), 0, 30);
        $filter = array_key_exists($request->string('status')->toString(), self::FILTERS) ? $request->string('status')->toString() : 'all';

        $base = fn () => VisibleOrders::query(self::user())->where('shift_id', $shift?->id);
        $orders = $shift === null ? collect() : $base()
            ->when($filter !== 'all', fn ($q) => $q->whereIn('status', self::FILTERS[$filter]))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('order_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('table_label', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->with(['user:id,name', 'payments.method:id,name'])
            ->latest()
            ->limit(200)
            ->get();

        return view('cashier.orders.index', [
            'shift' => $shift,
            'orders' => $orders,
            'search' => $search,
            'filter' => $filter,
            'stats' => $shift === null ? null : self::stats($base()),
            'timezone' => CurrentOutlet::timezone(),
        ]);
    }

    /**
     * Kartu statistik shift (tidak ikut cari/filter): transaksi & omzet = order selesai, rata-rata per transaksi, void.
     *
     * @param  Builder<Order>  $query
     * @return array{count: int, revenue: string, average: string, voided: int}
     */
    private static function stats(Builder $query): array
    {
        $row = $query->toBase()->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN status = ? THEN grand_total ELSE 0 END) AS revenue, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS voided',
            [OrderStatus::Completed->value, OrderStatus::Completed->value, OrderStatus::Voided->value],
        )->first();
        $count = (int) ($row->done ?? 0);
        $revenue = Money::fromDb($row->revenue ?? '0');

        return [
            'count' => $count,
            'revenue' => $revenue,
            'average' => $count > 0 ? Money::div($revenue, $count) : '0.00',
            'voided' => (int) ($row->voided ?? 0),
        ];
    }

    /** Detail order + form void. */
    public function show(string $order): View
    {
        $model = $this->visible($order);

        return view('cashier.orders.show', [
            'order' => $model->load(['items.options', 'payments.method', 'user:id,name']),
            'timezone' => CurrentOutlet::timezone(),
            'canVoidSelf' => self::user()->can('order.void'),
            'approvers' => PinApprovers::for(self::user(), 'order.void', $model->outlet_id),
            // Pecah & gabung open bill (ADR 0019)
            'newOrderId' => (string) Str::uuid7(),
            'otherBills' => Order::query()->where('outlet_id', $model->outlet_id)->where('status', OrderStatus::Open)
                ->whereKeyNot($model->id)->where('paid_total', 0)->orderBy('order_number')->get(['id', 'order_number', 'table_label', 'grand_total']),
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

    /** Pecah open bill ke bill baru (ADR 0019). */
    public function split(Request $request, string $order, SplitOpenBill $split): RedirectResponse
    {
        abort_unless(self::user()->can('order.create'), 403);
        $model = $this->visible($order);
        $data = $request->validate(SplitOpenBillRequest::fieldRules(), [], ['items' => 'item', 'items.*.qty' => 'qty', 'table_label' => 'meja']);

        $result = $split->handle(self::user(), WebCashier::device(), $model, (string) $data['new_order_id'], SplitOpenBillRequest::moves($data), $data['table_label'] ?? null);

        return redirect()->route('cashier.orders.show', $result['target']->id)->with('status', 'Bill baru '.$result['target']->order_number.' dibuat');
    }

    /** Gabung open bill lain ke bill ini (ADR 0019). */
    public function merge(Request $request, string $order, MergeOpenBills $merge): RedirectResponse
    {
        abort_unless(self::user()->can('order.create'), 403);
        $model = $this->visible($order);
        $data = $request->validate(['source_order_id' => ['required', 'uuid']], [], ['source_order_id' => 'bill']);
        $source = $this->visible((string) $data['source_order_id']);

        $merge->handle(self::user(), WebCashier::device(), $model, $source);

        return redirect()->route('cashier.orders.show', $model->id)->with('status', 'Bill '.$source->order_number.' digabung');
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
