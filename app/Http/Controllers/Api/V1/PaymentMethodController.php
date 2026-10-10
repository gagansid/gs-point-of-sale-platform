<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\UpdatePaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\PaymentMethodRequest;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Setelan')]
final class PaymentMethodController extends Controller
{
    /**
     * Daftar metode pembayaran (termasuk nonaktif).
     *
     * Permission payment_method.manage (owner). Kasir memakai /catalog (hanya yang aktif).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('payment_method.manage'), 403);

        $methods = PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(PaymentMethodResource::collection($methods)->resolve($request));
    }

    /**
     * Ubah metode pembayaran.
     *
     * Nama, wajib nomor referensi, aktif, urutan. Tunai tidak bisa dinonaktifkan (422).
     */
    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod, UpdatePaymentMethod $action): JsonResponse
    {
        /** @var array{name?: string, requires_reference?: bool, is_active?: bool, sort_order?: int} $changes */
        $changes = $request->validated();

        return ApiResponse::success(
            PaymentMethodResource::make($action->handle($paymentMethod, $changes))->resolve($request),
            'Metode pembayaran berhasil diperbarui',
        );
    }
}
