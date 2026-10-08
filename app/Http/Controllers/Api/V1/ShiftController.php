<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shift\CloseShift;
use App\Actions\Shift\OpenShift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shift\CloseShiftRequest;
use App\Http\Requests\Api\V1\Shift\OpenShiftRequest;
use App\Http\Resources\Api\V1\ShiftResource;
use App\Models\Shift;
use App\Services\Shift\ShiftSummary;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Shift')]
final class ShiftController extends Controller
{
    /**
     * Shift terbuka di device ini.
     *
     * `data: null` bila belum ada shift terbuka. Token harus dari login di device kasir terdaftar.
     */
    public function current(Request $request): JsonResponse
    {
        $device = ApiActor::userDevice($request);
        $shift = Shift::query()->with('openedBy')->where('open_device_key', $device->id)->first();

        return ApiResponse::success($shift !== null ? ShiftResource::make($shift)->resolve($request) : null);
    }

    /**
     * Buka shift.
     *
     * Satu shift terbuka per device. Bila sudah ada, shift itu yang dikembalikan
     * (200, meta.idempotent_replay = true).
     */
    public function store(OpenShiftRequest $request, OpenShift $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            Money::of($request->string('opening_cash')->toString()),
        );

        return ApiResponse::success(
            ShiftResource::make($result['shift']->load('openedBy'))->resolve($request),
            $result['replayed'] ? 'Shift sudah terbuka' : 'Shift berhasil dibuka',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Tutup shift.
     *
     * Hanya pembuka shift. Mengembalikan ringkasan penjualan & selisih kas.
     * Menutup ulang shift yang sudah ditutup mengembalikan hasil yang sama.
     */
    public function close(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary): JsonResponse
    {
        return $this->closeWith($request, $shift, $action, $summary, force: false);
    }

    /**
     * Tutup paksa shift.
     *
     * Permission shift.force_close (owner, manager, supervisor) untuk kasir yang lupa menutup /
     * device rusak. Catatan wajib.
     */
    public function forceClose(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary): JsonResponse
    {
        return $this->closeWith($request, $shift, $action, $summary, force: true);
    }

    /**
     * Ringkasan shift.
     *
     * Penjualan per metode bayar dan kas. Shift sendiri, atau semua shift dengan shift.view_all.
     */
    public function summary(Request $request, Shift $shift, ShiftSummary $summary): JsonResponse
    {
        Gate::forUser(ApiActor::user($request))->authorize('view', $shift);

        return ApiResponse::success([
            'shift' => ShiftResource::make($shift->load('openedBy'))->resolve($request),
            'summary' => $summary->for($shift),
        ]);
    }

    private function closeWith(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary, bool $force): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            $shift,
            Money::of($request->string('actual_cash')->toString()),
            $request->filled('note') ? $request->string('note')->toString() : null,
            $force,
        );

        $closed = $result['shift']->load('openedBy');

        return ApiResponse::success([
            'shift' => ShiftResource::make($closed)->resolve($request),
            'summary' => $summary->for($closed),
        ], 'Shift berhasil ditutup', meta: ['idempotent_replay' => $result['replayed']]);
    }
}
