<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Services\Order\MemberPricing;

/**
 * Potongan program member untuk satu order (ADR 0013, Q63): diskon member % (M3). Tanpa member, member
 * nonaktif, atau outlet dikecualikan = tanpa potongan. Dipakai checkout, open bill, dan pratinjau kasir.
 */
final class ResolveMemberPricing
{
    public function handle(?Member $member, Outlet $outlet): MemberPricing
    {
        if ($member === null || ! $member->isUsable() || ! OutletMemberProgram::activeAt($outlet->id)) {
            return MemberPricing::none();
        }

        return new MemberPricing(
            memberPercent: (string) MemberSetting::current()->member_discount_percent,
        );
    }
}
