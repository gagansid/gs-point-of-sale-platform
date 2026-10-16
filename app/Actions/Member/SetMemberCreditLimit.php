<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Member;
use App\Support\Money;

/** Batas kasbon per member (ADR 0023; izin member.manage); null = batas bawaan program. Saldo tidak berubah. */
final class SetMemberCreditLimit
{
    public function handle(Member $member, ?string $limit): Member
    {
        $member->forceFill(['credit_limit' => filled($limit) ? Money::max(Money::of((string) $limit), '0.00') : null])->save();

        return $member;
    }
}
