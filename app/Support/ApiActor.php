<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Pemilik token API pada request saat ini.
 *
 * Sanctum bisa mengembalikan User ATAU Device (device token, ADR 0002), sedangkan
 * Request::user() hanya bertipe user dari config auth. Kelas ini membaca pemilik token
 * apa adanya lalu memastikan tipenya.
 */
final class ApiActor
{
    public static function resolve(Request $request): mixed
    {
        return ($request->getUserResolver())();
    }

    /** @throws BusinessException */
    public static function device(Request $request): Device
    {
        $actor = self::resolve($request);

        return $actor instanceof Device ? $actor : throw BusinessException::of(ErrorCode::DeviceNotRegistered);
    }

    /** @throws BusinessException */
    public static function user(Request $request): User
    {
        $actor = self::resolve($request);

        return $actor instanceof User ? $actor : throw BusinessException::of(ErrorCode::Unauthenticated);
    }
}
