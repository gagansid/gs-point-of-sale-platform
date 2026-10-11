<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\CheckoutOrder;
use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\RejectSelfOrder;
use App\Enums\ErrorCode;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\SelfOrderResource;
use App\Models\Option;
use App\Models\Order;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Self-order')]
final class SelfOrderController extends Controller
{
    /**
     * Pesanan masuk dari meja.
     *
     * Permission self_order.handle. Pesanan `pending` di outlet perangkat (paling lama di atas, maks. 50) untuk menu
     * **Pesanan masuk**; polling 5–10 detik. `meta.unpaid_count` = pesanan meja diterima yang belum dibayar
     * (daftarnya: `GET /orders?status=awaiting_payment&channel=self_order`).
     */
    public function index(Request $request): JsonResponse
    {
        $outletId = self::outletId($request);

        $orders = SelfOrder::query()->with(['diningTable:id,name', 'member:id,name'])
            ->where('outlet_id', $outletId)->where('status', SelfOrderStatus::Pending)->oldest()->limit(50)->get();

        $productIds = $orders->flatMap(fn (SelfOrder $o): array => array_column($o->items, 'product_id'))->unique()->values();
        $optionIds = $orders->flatMap(fn (SelfOrder $o): array => array_merge(...array_column($o->items, 'option_ids') ?: [[]]))->unique()->values();
        $names = [
            'products' => Product::query()->withTrashed()->whereIn('id', $productIds)->pluck('name', 'id')->all(),
            'options' => Option::query()->whereIn('id', $optionIds)->pluck('name', 'id')->all(),
        ];

        return ApiResponse::success(
            $orders->map(fn (SelfOrder $o): array => SelfOrderResource::make($o)->additional($names)->resolve($request))->all(),
            'Pesanan masuk',
            meta: [
                'pending_count' => $orders->count(),
                'unpaid_count' => Order::query()->where('outlet_id', $outletId)->where('channel', OrderChannel::SelfOrder)
                    ->where('status', OrderStatus::AwaitingPayment)->count(),
            ],
        );
    }

    /**
     * Terima pesanan meja.
     *
     * Permission self_order.handle; butuh shift terbuka di perangkat. Order dibuat dengan status `awaiting_payment`
     * (nomor order, harga & member/voucher dihitung ulang), lalu dibayar lewat `POST /orders/{id}/payments`.
     * Sudah ditangani kasir lain → `409 ORDER_NOT_PENDING`; lewat batas waktu → `409 ORDER_EXPIRED`.
     */
    public function accept(Request $request, string $selfOrderId, AcceptSelfOrder $action): JsonResponse
    {
        $order = $action->handle(ApiActor::user($request), ApiActor::userDevice($request), self::find($selfOrderId));

        return ApiResponse::success(OrderResource::make(CheckoutOrder::load($order))->resolve($request), 'Pesanan diterima', 201);
    }

    /**
     * Tolak pesanan meja.
     *
     * Permission self_order.handle. `reason` wajib (tampil di HP pelanggan). Sudah ditangani → `409 ORDER_NOT_PENDING`.
     */
    public function reject(Request $request, string $selfOrderId, RejectSelfOrder $action): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $selfOrder = $action->handle(ApiActor::user($request), self::outletId($request), self::find($selfOrderId), $request->string('reason')->toString());

        return ApiResponse::success(['id' => $selfOrder->id, 'status' => $selfOrder->status->value, 'reject_reason' => $selfOrder->reject_reason], 'Pesanan ditolak');
    }

    /** @throws BusinessException */
    private static function find(string $id): SelfOrder
    {
        return SelfOrder::query()->find($id) ?? throw BusinessException::of(ErrorCode::NotFound);
    }

    /** Outlet perangkat; izin self_order.handle. */
    private static function outletId(Request $request): string
    {
        $user = ApiActor::user($request);
        abort_unless($user->can('self_order.handle'), 403);

        return ApiActor::userDevice($request)->outlet_id;
    }
}
