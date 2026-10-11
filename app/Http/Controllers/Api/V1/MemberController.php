<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Member\Data\MemberData;
use App\Actions\Member\SaveMember;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Member\MemberRequest;
use App\Http\Resources\Api\V1\MemberResource;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

#[Group('Member')]
final class MemberController extends Controller
{
    /** Batas pencarian per user per menit (cegah menebak nomor HP, ADR 0013) */
    private const SEARCH_PER_MINUTE = 30;

    /**
     * Cari member.
     *
     * Permission member.view. `search` = nomor HP (format apa pun), kode member, nama, atau 4+ digit terakhir HP;
     * maks. 20 hasil, HP disamarkan. Maks. 30 pencarian/menit per user (`429 TOO_MANY_REQUESTS`).
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('member.view') ?? false, 403);
        self::ensureProgramEnabled();
        $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);

        $key = 'member-search:'.$request->user()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, self::SEARCH_PER_MINUTE)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu banyak pencarian member. Coba lagi dalam '.RateLimiter::availableIn($key).' detik');
        }
        RateLimiter::hit($key, 60);

        $members = Member::query()->with('tier')->whereNull('anonymized_at')
            ->search($request->string('search')->toString())
            ->orderByDesc('last_visit_at')->orderBy('name')
            ->limit(20)->get();

        return ApiResponse::success(MemberResource::collection($members)->resolve($request), 'Daftar member');
    }

    /**
     * Daftar member.
     *
     * Permission member.create. `consent` wajib `true` (UU PDP). `id` opsional (UUID dari app) sebagai idempotency key.
     * Nomor HP dinormalisasi ke +62…; sudah terdaftar → `422 VALIDATION_ERROR` field `phone`.
     */
    public function store(MemberRequest $request, SaveMember $action): JsonResponse
    {
        self::ensureProgramEnabled();
        $result = $action->handle(null, MemberData::fromArray($request->validated()), CurrentOutlet::get());

        return ApiResponse::success(
            MemberResource::make($result['member'])->resolve($request),
            'Member berhasil didaftarkan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Detail member.
     *
     * Permission member.view. Member tenant lain atau dihapus → `404 MEMBER_NOT_FOUND`.
     */
    public function show(Request $request, string $memberId): JsonResponse
    {
        abort_unless($request->user()?->can('member.view') ?? false, 403);
        self::ensureProgramEnabled();

        $member = Member::query()->whereNull('anonymized_at')->find($memberId)
            ?? throw BusinessException::of(ErrorCode::MemberNotFound);

        return ApiResponse::success(MemberResource::make($member)->resolve($request), 'Detail member');
    }

    /**
     * Riwayat poin member.
     *
     * Permission member.view. 50 mutasi terbaru: `type` (earn, redeem, void_reversal, adjust, expire), `points`
     * (+/−), `balance_after`, `expires_at` untuk poin didapat.
     */
    public function points(Request $request, string $memberId): JsonResponse
    {
        abort_unless($request->user()?->can('member.view') ?? false, 403);
        self::ensureProgramEnabled();

        $member = Member::query()->whereNull('anonymized_at')->find($memberId)
            ?? throw BusinessException::of(ErrorCode::MemberNotFound);

        $entries = MemberPointEntry::query()->where('member_id', $member->id)->latest()->limit(50)->get()
            ->map(fn (MemberPointEntry $e): array => [
                'id' => $e->id,
                'type' => $e->type->value,
                'type_label' => $e->type->getLabel(),
                'points' => $e->points,
                'balance_after' => $e->balance_after,
                'order_id' => $e->order_id,
                'expires_at' => $e->expires_at?->toIso8601ZuluString(),
                'reason' => $e->reason,
                'created_at' => $e->created_at->toIso8601ZuluString(),
            ]);

        return ApiResponse::success(['balance' => $member->points_balance, 'entries' => $entries], 'Riwayat poin');
    }

    /** @throws BusinessException */
    private static function ensureProgramEnabled(): void
    {
        if (! MemberSetting::current()->enabled) {
            throw BusinessException::of(ErrorCode::MemberProgramDisabled, 'Program member belum diaktifkan');
        }
    }
}
