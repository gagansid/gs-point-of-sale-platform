<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\LoginWithPin;
use App\Actions\Auth\Logout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\PinLoginRequest;
use App\Http\Resources\Api\V1\AuthTokenResource;
use App\Http\Resources\Api\V1\MeResource;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Auth & sistem')]
final class AuthController extends Controller
{
    /**
     * Login owner/manager.
     *
     * Email + kata sandi. Hanya owner & manager; kasir/supervisor memakai login PIN.
     * Rate limit 5/menit per IP + device. Token berlaku sesuai pos.tokens.owner_ttl.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, LoginWithPassword $action): JsonResponse
    {
        $result = $action->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_uid')->toString(),
        );

        return ApiResponse::success(AuthTokenResource::make($result)->resolve($request), 'Login berhasil');
    }

    /**
     * Login PIN kasir.
     *
     * Memakai device token (Authorization: Bearer {device_token}). PIN dikunci 15 menit setelah
     * 5 kali salah (423 PIN_LOCKED, error.details.retry_after dalam detik). Token berlaku maks. 16 jam.
     */
    public function pinLogin(PinLoginRequest $request, LoginWithPin $action): JsonResponse
    {
        $device = ApiActor::device($request);

        $result = $action->handle(
            $device,
            $request->string('user_id')->toString(),
            $request->string('pin')->toString(),
        );

        return ApiResponse::success(AuthTokenResource::make($result)->resolve($request), 'Login berhasil');
    }

    /**
     * User yang sedang login.
     *
     * User, tenant, outlet beserta setelan outlet (pajak, service, pembulatan, struk) dan daftar permission.
     */
    public function me(Request $request): JsonResponse
    {
        $user = ApiActor::user($request);

        return ApiResponse::success(MeResource::make($user)->resolve($request));
    }

    /**
     * Logout.
     *
     * Menghapus token yang sedang dipakai.
     */
    public function logout(Request $request, Logout $action): JsonResponse
    {
        $user = ApiActor::user($request);
        $action->handle($user->currentAccessToken());

        return ApiResponse::success(null, 'Logout berhasil');
    }
}
