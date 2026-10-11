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
use App\Services\Order\MemberPricing;
use App\Support\Money;

/**
 * Potongan program member untuk satu order (ADR 0013, Q63): gratis stempel yang dipilih (M6), diskon member % (M3)
 * + diskon tier (M5). Tanpa member, member nonaktif, atau outlet dikecualikan = tanpa potongan; benefit yang dipilih
 * saat itu ditolak. Dipakai checkout, open bill, dan pratinjau kasir.
 */
final class ResolveMemberPricing
{
    public function __construct(private readonly StampCards $stamps) {}

    /**
     * @param  list<ResolvedLine>  $lines
     *
     * @throws BusinessException VALIDATION_ERROR benefit tidak bisa dipakai
     */
    public function handle(?Member $member, Outlet $outlet, array $lines = [], ?BenefitSelection $selection = null): MemberPricing
    {
        $selection ??= BenefitSelection::none();

        if ($member === null || ! $member->isUsable() || ! OutletMemberProgram::activeAt($outlet->id)) {
            if ($selection->stampProgramIds !== []) {
                $message = $member === null ? 'Pasang member untuk memakai kartu stempel' : 'Kartu stempel tidak berlaku untuk member atau outlet ini';
                throw BusinessException::of(ErrorCode::ValidationError, $message, ['stamp_program_ids' => [$message]]);
            }

            return MemberPricing::none();
        }

        $tier = $member->tier;
        $free = $this->stamps->freeItems($member, $lines, $selection->stampProgramIds);

        return new MemberPricing(
            stampFree: $free['amount'],
            // Diskon tier ditambahkan ke diskon member dasar (Q64)
            memberPercent: Money::min(Money::add((string) MemberSetting::current()->member_discount_percent, $tier->discount_percent ?? '0.00'), '100.00'),
            tierName: $tier?->name,
            stampRedemptions: $free['redemptions'],
        );
    }
}
