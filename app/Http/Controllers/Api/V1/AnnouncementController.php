<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\ApiResponse;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Auth & sistem')]
final class AnnouncementController extends Controller
{
    /**
     * Pengumuman aktif.
     *
     * Banner dari tim gs.POS untuk semua bisnis (maks. 3, terbaru dulu). Teks polos. Daftar kosong bila
     * tidak ada pengumuman. Aplikasi boleh menyimpan ID yang sudah ditutup pengguna.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(Announcement::current()->map(fn (Announcement $a): array => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'starts_at' => Iso::dateTime($a->starts_at),
            'ends_at' => Iso::dateTime($a->ends_at),
        ])->values()->all());
    }
}
