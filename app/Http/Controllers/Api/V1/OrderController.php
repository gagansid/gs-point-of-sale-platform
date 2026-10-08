<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\CheckoutRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

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
}
