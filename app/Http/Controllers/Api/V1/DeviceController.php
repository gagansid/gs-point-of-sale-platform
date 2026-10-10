<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\PinUsers;
use App\Actions\Auth\RegisterDevice;
use App\Enums\AppPlatform;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterDeviceRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Http\Resources\Api\V1\PinUserResource;
use App\Models\Outlet;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Auth & sistem')]
final class DeviceController extends Controller
{
    /**
     * Daftarkan device kasir.
     *
     * Permission device.manage (owner). Mengembalikan device_token SATU KALI — simpan di secure
     * storage. Mendaftarkan ulang device yang sama mencabut device token lama. `outlet_id` opsional
     * (outlet aktif yang dipegang user; default outlet pertama).
     */
    public function store(RegisterDeviceRequest $request, RegisterDevice $action): JsonResponse
    {
        // Hanya outlet aktif yang dipegang user (ADR 0010); outlet lain → 404
        $outlets = ApiActor::user($request)->accessibleOutlets()->active();
        $outlet = $request->filled('outlet_id')
            ? $outlets->findOrFail($request->string('outlet_id')->toString())
            : $outlets->firstOrFail();

        $result = $action->handle(
            $outlet,
            $request->string('name')->toString(),
            $request->string('device_uid')->toString(),
            $request->enum('platform', AppPlatform::class),
            $request->filled('app_version') ? $request->string('app_version')->toString() : null,
        );

        return ApiResponse::success([
            'device' => DeviceResource::make($result['device'])->resolve($request),
            'device_token' => $result['token'],
        ], 'Perangkat berhasil didaftarkan', 201);
    }

    /**
     * Daftar karyawan untuk layar PIN.
     *
     * Memakai device token. Hanya karyawan aktif yang punya PIN dan terdaftar di outlet device.
     */
    public function cashiers(Request $request, string $deviceUid, PinUsers $action): JsonResponse
    {
        $device = ApiActor::device($request);

        // Device token hanya berlaku untuk device-nya sendiri
        if (! hash_equals($device->device_uid, $deviceUid)) {
            throw BusinessException::of(ErrorCode::DeviceNotRegistered);
        }

        return ApiResponse::success(PinUserResource::collection($action->handle($device))->resolve($request));
    }
}
