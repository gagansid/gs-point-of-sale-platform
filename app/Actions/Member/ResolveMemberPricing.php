<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Order\Data\ResolvedLine;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Services\Member\StampCards;
use App\Services\Member\Vouchers;
use App\Services\Order\MemberPricing;
use App\Support\Money;

/**
 * Potongan program member untuk satu order (ADR 0013, Q63): gratis stempel yang dipilih (M6), voucher (M7), diskon
 * member % (M3) + diskon tier (M5). Tanpa member, member nonaktif, atau outlet dikecualikan = tanpa potongan member
 * (kode promo tanpa syarat member tetap bisa dipakai di outlet yang ikut program); benefit yang tidak berlaku
 * ditolak. Dipakai checkout, open bill, dan pratinjau kasir.
 */
final class ResolveMemberPricing
{
    public function __construct(
        private readonly StampCards $stamps,
        private readonly Vouchers $vouchers,
    ) {}

    /**
     * @param  list<ResolvedLine>  $lines
     *
     * @throws BusinessException VALIDATION_ERROR (kartu stempel), VOUCHER_INVALID
     */
    public function handle(?Member $member, Outlet $outlet, array $lines = [], ?BenefitSelection $selection = null): MemberPricing
    {
        $selection ??= BenefitSelection::none();
        $eligible = $member !== null && $member->isUsable() && OutletMemberProgram::activeAt($outlet->id);

        if (! $eligible && $selection->stampProgramIds !== []) {
            $message = $member === null ? 'Pasang member untuk memakai kartu stempel' : 'Kartu stempel tidak berlaku untuk member atau outlet ini';
            throw BusinessException::of(ErrorCode::ValidationError, $message, ['stamp_program_ids' => [$message]]);
        }

        $voucher = $selection->hasVoucher()
            ? $this->vouchers->resolve($selection->voucherCode, $selection->voucherId, $member, $outlet->id, $lines)
            : null;

        if (! $eligible) {
            return $voucher === null ? MemberPricing::none() : new MemberPricing(
                voucherType: $voucher->type,
                voucherValue: $voucher->value,
                voucherMax: $voucher->max_discount,
                voucherId: $voucher->id,
                voucherLabel: $voucher->label(),
            );
        }

        $tier = $member->tier;
        $free = $this->stamps->freeItems($member, $lines, $selection->stampProgramIds);

        return new MemberPricing(
            stampFree: $free['amount'],
            voucherType: $voucher?->type,
            voucherValue: $voucher->value ?? '0.00',
            voucherMax: $voucher?->max_discount,
            // Diskon tier ditambahkan ke diskon member dasar (Q64)
            memberPercent: Money::min(Money::add((string) MemberSetting::current()->member_discount_percent, $tier->discount_percent ?? '0.00'), '100.00'),
            tierName: $tier?->name,
            stampRedemptions: $free['redemptions'],
            voucherId: $voucher?->id,
            voucherLabel: $voucher?->label(),
        );
    }
}
