<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\Data\CheckoutData;
use App\Actions\Shift\CloseShift;
use App\Actions\Shift\CorrectOpeningCash;
use App\Actions\Shift\Data\CashMovementData;
use App\Actions\Shift\OpenShift;
use App\Actions\Shift\RecordCashMovement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shift\CashMovementRequest;
use App\Http\Requests\Api\V1\Shift\CloseShiftRequest;
use App\Http\Requests\Api\V1\Shift\CorrectOpeningCashRequest;
use App\Http\Requests\Api\V1\Shift\OpenShiftRequest;
use App\Http\Resources\Api\V1\CashMovementResource;
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
     * `data: null` bila belum ada shift terbuka; `meta.carry_over_cash` = kas awal bila shift dibuka
     * sekarang (sisa laci shift sebelumnya, ADR 0017), null = kas awal wajib diketik.
     * Token harus dari login di device kasir terdaftar.
     */
    public function current(Request $request): JsonResponse
    {
        $device = ApiActor::userDevice($request);
        $shift = Shift::query()->with(['openedBy', 'operators.user:id,name'])->where('open_device_key', $device->id)->first();

        return ApiResponse::success(
            $shift !== null ? ShiftResource::make($shift)->resolve($request) : null,
            meta: ['carry_over_cash' => $shift === null ? OpenShift::carryOverFor($device) : null],
        );
    }

    /**
     * Buka shift.
     *
     * Satu shift terbuka per device. Bila sudah ada, shift itu yang dikembalikan
     * (200, meta.idempotent_replay = true) dan pengirim tercatat sebagai operator.
     * `opening_cash` kosong = carry-over; berbeda dari carry-over = koreksi (alasan + izin/PIN approver).
     */
    public function store(OpenShiftRequest $request, OpenShift $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            $request->filled('opening_cash') ? Money::of($request->string('opening_cash')->toString()) : null,
            $request->filled('opening_cash_note') ? $request->string('opening_cash_note')->toString() : null,
            CheckoutData::parseApproval($request->validated()),
        );

        return ApiResponse::success(
            ShiftResource::make($result['shift']->load(['openedBy', 'operators.user:id,name']))->resolve($request),
            $result['replayed'] ? 'Shift sudah terbuka' : 'Shift berhasil dibuka',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Tutup shift.
     *
     * Siapa saja yang bertugas di device shift ini (ADR 0017). Mengembalikan ringkasan penjualan & selisih kas.
     * `cash_deposited` kosong = kas aktual − modal laci tetap outlet (atau 0); sisanya menjadi kas awal
     * shift berikutnya. Menutup ulang shift yang sudah ditutup mengembalikan hasil yang sama.
     */
    public function close(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary): JsonResponse
    {
        return $this->closeWith($request, $shift, $action, $summary, force: false);
    }

    /**
     * Tutup paksa shift.
     *
     * Permission shift.force_close (owner, manager, supervisor) untuk shift di device lain (device rusak,
     * lupa ditutup). Catatan wajib.
     */
    public function forceClose(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary): JsonResponse
    {
        return $this->closeWith($request, $shift, $action, $summary, force: true);
    }

    /**
     * Ringkasan shift.
     *
     * Penjualan per metode bayar, kas masuk/keluar, dan kas seharusnya. Shift yang pernah dipegang user
     * (pembuka atau operator), atau semua shift dengan shift.view_all.
     */
    public function summary(Request $request, Shift $shift, ShiftSummary $summary): JsonResponse
    {
        Gate::forUser(ApiActor::user($request))->authorize('view', $shift);

        return ApiResponse::success([
            'shift' => ShiftResource::make($shift->load(['openedBy', 'operators.user:id,name']))->resolve($request),
            'summary' => $summary->for($shift),
        ]);
    }

    /**
     * Koreksi kas awal.
     *
     * Shift berjalan di device ini (ADR 0017). Izin shift.edit_opening_cash (owner, manager, supervisor) atau
     * PIN approver (kasir); alasan wajib. Kas awal semula tersimpan di `carried_cash`.
     */
    public function correctOpeningCash(CorrectOpeningCashRequest $request, Shift $shift, CorrectOpeningCash $action): JsonResponse
    {
        $updated = $action->handle(
            ApiActor::user($request),
            $shift,
            ApiActor::userDevice($request),
            Money::of($request->string('opening_cash')->toString()),
            $request->string('note')->toString(),
            CheckoutData::parseApproval($request->validated()),
        );

        return ApiResponse::success(ShiftResource::make($updated->load(['openedBy', 'operators.user:id,name']))->resolve($request), 'Kas awal berhasil dikoreksi');
    }

    /**
     * Daftar kas masuk/keluar shift.
     *
     * Hak lihat sama dengan ringkasan shift.
     */
    public function cashMovements(Request $request, Shift $shift): JsonResponse
    {
        Gate::forUser(ApiActor::user($request))->authorize('view', $shift);

        return ApiResponse::success(
            CashMovementResource::collection($shift->cashMovements()->with(['user:id,name', 'approver:id,name'])->get())->resolve($request),
        );
    }

    /**
     * Catat kas masuk/keluar.
     *
     * Ke shift yang terbuka di device ini (409 SHIFT_NOT_OPEN bila belum). `id` dari aplikasi = idempotency key.
     * Kas keluar di atas `cash_out_approval_limit` outlet: izin shift.cash_out_over_limit atau PIN approver
     * (APPROVAL_REQUIRED, `error.details.limit`). Kas keluar tidak boleh melebihi kas seharusnya di laci.
     */
    public function storeCashMovement(CashMovementRequest $request, RecordCashMovement $action): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            ApiActor::userDevice($request),
            CashMovementData::fromArray($request->validated()),
            CheckoutData::parseApproval($request->validated()),
        );

        return ApiResponse::success(
            CashMovementResource::make($result['movement']->load(['user:id,name', 'approver:id,name']))->resolve($request),
            $result['replayed'] ? 'Kas sudah tercatat' : 'Kas berhasil dicatat',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    private function closeWith(CloseShiftRequest $request, Shift $shift, CloseShift $action, ShiftSummary $summary, bool $force): JsonResponse
    {
        $result = $action->handle(
            ApiActor::user($request),
            $shift,
            ApiActor::userDevice($request),
            Money::of($request->string('actual_cash')->toString()),
            $request->filled('cash_deposited') ? Money::of($request->string('cash_deposited')->toString()) : null,
            $request->filled('note') ? $request->string('note')->toString() : null,
            $force,
        );

        $closed = $result['shift']->load(['openedBy', 'operators.user:id,name']);

        return ApiResponse::success([
            'shift' => ShiftResource::make($closed)->resolve($request),
            'summary' => $summary->for($closed),
        ], 'Shift berhasil ditutup', meta: ['idempotent_replay' => $result['replayed']]);
    }
}
