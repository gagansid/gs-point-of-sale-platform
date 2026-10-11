<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VoucherResource;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Voucher;
use App\Services\Member\Vouchers;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

#[Group('Member')]
final class VoucherController extends Controller
{
    /** Batas cek kode per user per menit (cegah menebak kode promo) */
    private const CHECK_PER_MINUTE = 30;

    /**
     * Cek kode voucher.
     *
     * Permission order.create. Memeriksa kode promo untuk outlet perangkat (dan member bila `member_id` dikirim):
     * periode, kuota, khusus member/tier, outlet; `subtotal` opsional untuk cek minimal belanja. Sah → data voucher
     * untuk pratinjau; tidak sah → `422 VOUCHER_INVALID` (`details.reason`). Potongan final dihitung saat checkout.
     * Maks. 30 cek/menit per user.
     */
    public function check(Request $request, Vouchers $vouchers): JsonResponse
    {
        abort_unless($request->user()?->can('order.create') ?? false, 403);

        if (! MemberSetting::current()->enabled) {
            throw BusinessException::of(ErrorCode::MemberProgramDisabled, 'Program member belum diaktifkan');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'member_id' => ['nullable', 'uuid'],
            'subtotal' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ]);

        $key = 'voucher-check:'.$request->user()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, self::CHECK_PER_MINUTE)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu banyak cek voucher. Coba lagi dalam '.RateLimiter::availableIn($key).' detik');
        }
        RateLimiter::hit($key, 60);

        $member = filled($data['member_id'] ?? null)
            ? (Member::query()->find($data['member_id']) ?? throw BusinessException::of(ErrorCode::MemberNotFound))
            : null;
        $voucher = Voucher::query()->where('code', Voucher::normalizeCode($data['code']))->first()
            ?? throw BusinessException::of(ErrorCode::VoucherInvalid, 'Kode voucher tidak ditemukan', ['reason' => 'not_found']);

        // Tanpa subtotal: minimal belanja dicek saat checkout (aplikasi memakai `min_spend` untuk pratinjau)
        $subtotal = filled($data['subtotal'] ?? null) ? Money::of((string) $data['subtotal']) : Money::MAX;
        $vouchers->validate($voucher, $member, CurrentOutlet::get()->id, $subtotal);

        return ApiResponse::success(VoucherResource::make($voucher)->resolve($request), 'Voucher bisa dipakai');
    }
}
