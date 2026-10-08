<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\AppVersionResource;
use App\Models\Announcement;
use App\Models\AppVersion;
use App\Support\ApiResponse;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Auth & sistem')]
final class SystemController extends Controller
{
    /**
     * Status sistem.
     *
     * Publik, tanpa token dan tanpa X-App-Version. Dipanggil app saat dibuka untuk mengecek
     * versi minimal dan pengumuman. Saat maintenance, endpoint ini mengembalikan 503 MAINTENANCE.
     *
     * @unauthenticated
     */
    public function status(): JsonResponse
    {
        return ApiResponse::success([
            'maintenance' => false,
            'server_time' => Iso::dateTime(now()),
            'app_versions' => AppVersionResource::collection(AppVersion::query()->orderBy('platform')->get())->resolve(),
            'announcements' => AnnouncementResource::collection(Announcement::query()->active()->latest('starts_at')->get())->resolve(),
        ]);
    }
}
