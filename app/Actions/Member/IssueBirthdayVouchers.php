<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\DiscountType;
use App\Enums\VoucherSource;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Voucher;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Voucher ulang tahun (ADR 0013, Q66): untuk setiap member aktif yang berulang tahun bulan ini dibuat satu voucher
 * pribadi, berlaku sampai akhir bulan, sekali pakai. Command `pos:issue-birthday-vouchers` harian (WIB); aman
 * diulang (satu voucher per member per bulan).
 */
final class IssueBirthdayVouchers
{
    /** Zona waktu bulan lahir; sama dengan jadwal member lain (WIB). */
    public const TIMEZONE = 'Asia/Jakarta';

    /** @return int jumlah voucher yang dibuat */
    public function handle(?CarbonImmutable $now = null): int
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
        $start = $local->startOfMonth()->utc();
        $end = $local->endOfMonth()->utc();
        $issued = 0;

        foreach (MemberSetting::allTenants()->where('enabled', true)->pluck('tenant_id') as $tenantId) {
            $issued += TenantContext::run((string) $tenantId, function () use ($local, $start, $end): int {
                $rule = MemberSetting::current()->birthdayVoucher();

                if (! $rule['enabled'] || (float) $rule['value'] <= 0) {
                    return 0;
                }

                $count = 0;
                Member::query()->where('is_active', true)->whereNull('anonymized_at')->where('birth_month', $local->month)
                    ->whereDoesntHave('vouchers', fn ($q) => $q->where('source', VoucherSource::Birthday)->where('starts_at', $start))
                    ->chunkById(200, function ($members) use ($rule, $start, $end, &$count): void {
                        foreach ($members as $member) {
                            DB::transaction(function () use ($member, $rule, $start, $end): void {
                                $voucher = new Voucher([
                                    'name' => 'Voucher ulang tahun',
                                    'type' => DiscountType::from($rule['type']),
                                    'value' => $rule['value'],
                                    'max_discount' => $rule['type'] === DiscountType::Percent->value ? $rule['max_discount'] : null,
                                    'min_spend' => $rule['min_spend'],
                                    'quota' => 1,
                                    'per_member_quota' => 1,
                                    'starts_at' => $start,
                                    'ends_at' => $end,
                                    'member_only' => true,
                                ]);
                                $voucher->forceFill(['source' => VoucherSource::Birthday, 'member_id' => $member->id])->save();
                            });
                            $count++;
                        }
                    });

                return $count;
            });
        }

        return $issued;
    }
}
