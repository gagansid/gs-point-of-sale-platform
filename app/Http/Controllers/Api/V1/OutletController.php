<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outlet\Data\OutletSettingsData;
use App\Actions\Outlet\UpdateOutletSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outlet\OutletSettingsRequest;
use App\Http\Resources\Api\V1\OutletResource;
use App\Http\Resources\Api\V1\OutletSummaryResource;
use App\Models\User;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Setelan')]
final class OutletController extends Controller
{
    /**
     * Daftar outlet.
     *
     * Outlet yang dipegang user (owner: semua outlet bisnis), termasuk yang nonaktif (`is_active`).
     * `meta.current_outlet_id` = outlet perangkat. Untuk pemilih outlet laporan (ADR 0010).
     */
    public function index(Request $request): JsonResponse
    {
        $user = ApiActor::user($request);

        return ApiResponse::success(
            OutletSummaryResource::collection($user->accessibleOutlets()->get())->resolve($request),
            meta: ['current_outlet_id' => CurrentOutlet::selectedId()],
        );
    }

    /**
     * Setelan outlet.
     *
     * Permission outlet.settings (owner). Outlet perangkat; login tanpa perangkat: outlet pertama.
     */
    public function show(Request $request): JsonResponse
    {
        // Melihat: role berizin, tetap bisa saat hanya-baca (ADR 0009); mengubah: can() di FormRequest
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('outlet.settings'), 403);

        return ApiResponse::success(OutletResource::make(CurrentOutlet::getOrFail())->resolve($request));
    }

    /**
     * Ubah setelan outlet.
     *
     * Field yang tidak dikirim tetap memakai nilai lama. Kode outlet tidak bisa diubah (awalan nomor
     * order). Order lama tidak berubah karena menyimpan tarifnya sendiri.
     */
    public function update(OutletSettingsRequest $request, UpdateOutletSettings $action): JsonResponse
    {
        $outlet = CurrentOutlet::getOrFail();

        $data = OutletSettingsData::fromArray([
            ...$outlet->only(['name', 'address', 'timezone', 'tax_rate', 'tax_inclusive', 'service_charge_rate', 'rounding', 'receipt_header', 'receipt_footer', 'receipt_show_rounding']),
            'discount_limits' => $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits'),
            ...$request->validated(),
        ]);

        return ApiResponse::success(
            OutletResource::make($action->handle($outlet, $data))->resolve($request),
            'Setelan outlet berhasil disimpan',
        );
    }
}
