<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VisibleOrders;
use App\Actions\Order\VoidOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\AddPaymentRequest;
use App\Http\Requests\Api\V1\Order\CheckoutRequest;
use App\Http\Requests\Api\V1\Order\OpenBillRequest;
use App\Http\Requests\Api\V1\Order\OrderIndexRequest;
use App\Http\Requests\Api\V1\Order\VoidOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
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
                $timezone = (string) (Outlet::query()->orderBy('created_at')->value('timezone') ?? config('pos.default_timezone'));
                $day = CarbonImmutable::createFromFormat('Y-m-d', $request->string('date')->toString(), $timezone) ?: CarbonImmutable::now($timezone);
                $query->whereBetween('created_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()]);
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
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
}
