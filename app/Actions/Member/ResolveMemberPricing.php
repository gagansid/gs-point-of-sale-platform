<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Services\Order\MemberPricing;
use App\Support\Money;

/**
 * Potongan program member untuk satu order (ADR 0013, Q63): diskon member % (M3) + diskon tier (M5). Tanpa member, member
 * nonaktif, atau outlet dikecualikan = tanpa potongan. Dipakai checkout, open bill, dan pratinjau kasir.
 */
final class ResolveMemberPricing
{
    public function handle(?Member $member, Outlet $outlet): MemberPricing
    {
        if ($member === null || ! $member->isUsable() || ! OutletMemberProgram::activeAt($outlet->id)) {
            return MemberPricing::none();
        }

        $tier = $member->tier;

        return new MemberPricing(
            // Diskon tier ditambahkan ke diskon member dasar (Q64)
            memberPercent: Money::min(Money::add((string) MemberSetting::current()->member_discount_percent, $tier->discount_percent ?? '0.00'), '100.00'),
            tierName: $tier?->name,
        );
    }
}
