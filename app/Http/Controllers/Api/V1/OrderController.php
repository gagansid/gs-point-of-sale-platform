<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\ResolveOrderMember;
use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\Data\RefundData;
use App\Actions\Order\MergeOpenBills;
use App\Actions\Order\QuoteOrder;
use App\Actions\Order\RefundableOrders;
use App\Actions\Order\RefundOrder;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\SplitOpenBill;
use App\Actions\Order\VisibleOrders;
use App\Actions\Order\VoidOrder;
use App\Enums\DiscountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\AddPaymentRequest;
use App\Http\Requests\Api\V1\Order\CheckoutRequest;
use App\Http\Requests\Api\V1\Order\MergeOpenBillRequest;
use App\Http\Requests\Api\V1\Order\OpenBillRequest;
use App\Http\Requests\Api\V1\Order\OrderIndexRequest;
use App\Http\Requests\Api\V1\Order\QuoteOrderRequest;
use App\Http\Requests\Api\V1\Order\RefundOrderRequest;
use App\Http\Requests\Api\V1\Order\SplitOpenBillRequest;
use App\Http\Requests\Api\V1\Order\VoidOrderRequest;
use App\Http\Resources\Api\V1\OrderRefundResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Order & pembayaran')]
final class OrderController extends Controller
{
    /**
     * Checkout.
     *
     * Buat order + bayar sekaligus (takeaway/retail). Butuh shift terbuka di device. Server menghitung
     * ulang semua nominal; kirim hanya produk, opsi, qty, diskon, dan pembayaran. `id` order & payment
     * dari app = idempotency key: request ulang → 200 dengan order yang sama (meta.idempotent_replay).
     * Tunai boleh melebihi sisa (kembalian); non-tunai tidak (PAYMENT_EXCEEDS_BALANCE).
     */
    public function checkout(CheckoutRequest $request, CheckoutOrder $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            CheckoutData::fromArray($request->validated()),
        );

        return ApiResponse::success(
            OrderResource::make($result['order'])->resolve($request),
            'Transaksi berhasil disimpan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Simpan / ubah open bill.
     *
     * Dine-in. Upsert berdasarkan `{id}` dari app; item dikirim lengkap dan menggantikan item lama.
     * Stok dipotong saat lunas. Order selesai/void → 409 ORDER_ALREADY_CLOSED.
     */
    public function saveOpenBill(OpenBillRequest $request, string $orderId, SaveOpenBill $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            $orderId,
            OpenBillData::fromArray($request->validated()),
        );

        return ApiResponse::success(
            OrderResource::make($result['order'])->resolve($request),
            $result['created'] ? 'Open bill berhasil disimpan' : 'Open bill berhasil diperbarui',
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * Tambah pembayaran ke open bill.
     *
     * Boleh sebagian (split bill). Lunas → order selesai, stok dipotong. `payments[].id` = idempotency key.
     */
    public function addPayment(AddPaymentRequest $request, Order $order, AddPayment $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            $order,
            CheckoutData::parsePayments($request->validated('payments')),
            $request->validated('member_code'),
            $request->validated('member_pin'),
        );

        return ApiResponse::success(
            OrderResource::make($result['order'])->resolve($request),
            'Pembayaran berhasil disimpan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Riwayat transaksi.
     *
     * Filter: date (YYYY-MM-DD, tanggal lokal outlet), status, shift_id, search (nomor order/meja).
     * Kasir: order di shift sendiri + semua open bill. Supervisor: semua order hari ini. Owner/manager: semua.
     */
    public function index(OrderIndexRequest $request): JsonResponse
    {
        $user = ApiActor::user($request);
        $search = $request->string('search')->trim()->toString();

        $orders = VisibleOrders::query($user)
            ->when($request->filled('date'), function (Builder $query) use ($request): void {
                $date = $request->string('date')->toString();
                $query->whereBetween('created_at', CurrentOutlet::utcRange($date, $date));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('shift_id'), fn (Builder $q) => $q->where('shift_id', $request->string('shift_id')->toString()))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('order_number', 'like', $like)->orWhere('table_label', 'like', $like));
            })
            ->latest()
            ->paginate(min($request->integer('per_page', (int) config('pos.pagination.per_page')), (int) config('pos.pagination.max_per_page')));

        return ApiResponse::paginated($orders, OrderResource::class);
    }

    /**
     * Detail order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        Gate::forUser(ApiActor::user($request))->authorize('view', $order);

        return ApiResponse::success(OrderResource::make(CheckoutOrder::load($order))->resolve($request));
    }

    /**
     * Data struk siap cetak.
     *
     * Permission order.reprint. Memakai snapshot order (tarif saat transaksi) dan waktu lokal outlet.
     */
    public function receipt(Request $request, Order $order): JsonResponse
    {
        Gate::forUser(ApiActor::user($request))->authorize('reprint', $order);

        return ApiResponse::success(ReceiptResource::make(CheckoutOrder::load($order)->load(['outlet', 'user']))->resolve($request));
    }

    /**
     * Batalkan order (void).
     *
     * Hanya order di shift yang masih terbuka; alasan wajib. Kasir wajib PIN approver
     * (owner/manager/supervisor), tidak boleh diri sendiri. Stok dikembalikan, pembayaran di-void.
     */
    public function void(VoidOrderRequest $request, Order $order, VoidOrder $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            $order,
            $request->string('reason')->toString(),
            CheckoutData::parseApproval($request->validated()),
        );

        return ApiResponse::success(
            OrderResource::make($result['order'])->resolve($request),
            'Transaksi berhasil dibatalkan',
            meta: ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Retur sebagian.
     *
     * Order selesai di outlet device, maks. `pos.refund.max_days` hari (bawaan 30). Butuh shift terbuka di device
     * (uang tunai keluar dari laci). Kasir wajib PIN approver (`order.refund`). Nilai dihitung server (bagian
     * proporsional grand total). `id` dari app = idempotency key (ADR 0018).
     */
    public function refund(RefundOrderRequest $request, Order $order, RefundOrder $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            $order,
            RefundData::fromArray($request->validated()),
            CheckoutData::parseApproval($request->validated()),
        );

        return ApiResponse::success(
            OrderRefundResource::make($result['refund'])->resolve($request),
            $result['replayed'] ? 'Retur sudah tercatat' : 'Retur berhasil dicatat',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Cari transaksi untuk diretur.
     *
     * Nomor order di outlet device, status selesai, dalam batas hari retur; selain itu 404. Mengembalikan order
     * beserta item (`refunded_qty`) dan riwayat retur.
     */
    public function refundable(Request $request, string $number): JsonResponse
    {
        $order = RefundableOrders::find($number, ApiActor::userDevice($request)->outlet_id);
        abort_if($order === null, 404);

        return ApiResponse::success(OrderResource::make(CheckoutOrder::load($order)->load('refunds.items'))->resolve($request));
    }

    /**
     * Pecah open bill.
     *
     * Item & qty dipindah ke open bill baru (`new_order_id` dari app = idempotency key). Kedua bill dihitung ulang.
     * Syarat: open bill di outlet device, belum ada pembayaran, tanpa diskon/voucher/kartu stempel (ADR 0019).
     */
    public function split(SplitOpenBillRequest $request, Order $order, SplitOpenBill $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            $order,
            $request->string('new_order_id')->toString(),
            SplitOpenBillRequest::moves($request->validated()),
            $request->filled('table_label') ? $request->string('table_label')->toString() : null,
        );

        return ApiResponse::success([
            'source' => OrderResource::make($result['source'])->resolve($request),
            'target' => OrderResource::make($result['target'])->resolve($request),
        ], $result['replayed'] ? 'Open bill sudah dipecah' : 'Open bill berhasil dipecah', $result['replayed'] ? 200 : 201, ['idempotent_replay' => $result['replayed']]);
    }

    /**
     * Gabung open bill.
     *
     * Semua item `source_order_id` ditambahkan ke open bill ini; bill sumber berstatus `merged`. Syarat sama dengan
     * pecah; dua member berbeda ditolak. Gabung ulang yang sama = hasil sama (ADR 0019).
     */
    public function merge(MergeOpenBillRequest $request, Order $order, MergeOpenBills $action): JsonResponse
    {
        $source = Order::query()->findOrFail($request->string('source_order_id')->toString());
        $result = $action->handle(ApiActor::user($request), ApiActor::userDevice($request), $order, $source);

        return ApiResponse::success(
            OrderResource::make($result['order'])->resolve($request),
            'Open bill berhasil digabung',
            meta: ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Pratinjau total.
     *
     * Total keranjang dihitung server tanpa menyimpan apa pun: promo otomatis yang berlaku saat ini (ADR 0022),
     * member, voucher, stempel, pajak, service, pembulatan. Batas diskon kasir & pembayaran dicek saat checkout.
     */
    public function quote(QuoteOrderRequest $request, QuoteOrder $action): JsonResponse
    {
        $data = $request->validated();
        $discount = $data['discount'] ?? null;
        $member = app(ResolveOrderMember::class)->handle(filled($data['member_id'] ?? null) ? (string) $data['member_id'] : null);
        $outlet = Outlet::query()->findOrFail(ApiActor::userDevice($request)->outlet_id);

        $totals = $action->handle(
            $outlet,
            (array) $data['items'],
            is_array($discount) ? DiscountType::from((string) $discount['type']) : null,
            is_array($discount) ? Money::of((string) $discount['value']) : '0.00',
            $member,
            BenefitSelection::fromArray($data),
        );

        return ApiResponse::success([
            'subtotal' => $totals->subtotal,
            'promo_discount' => $totals->promoDiscount,
            'promotions' => $totals->promotions,
            'stamp_free_total' => $totals->stampDiscount,
            'order_discount' => $totals->orderDiscount,
            'voucher_discount' => $totals->voucherDiscount,
            'member_discount' => $totals->memberDiscount,
            'discount_total' => $totals->discountTotal,
            'service_total' => $totals->serviceTotal,
            'tax_total' => $totals->taxTotal,
            'rounding' => $totals->rounding,
            'grand_total' => $totals->grandTotal,
            'lines' => $totals->lines,
        ]);
    }
}
