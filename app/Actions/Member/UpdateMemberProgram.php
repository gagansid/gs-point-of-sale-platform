<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Setelan program member (ADR 0013, Q60): aktif di bisnis & outlet yang ikut. Outlet di luar $enabledOutletIds
 * dikecualikan (baris is_enabled = false); tanpa baris = ikut.
 */
final class UpdateMemberProgram
{
    /**
     * @param  list<string>  $enabledOutletIds  outlet yang ikut program (hanya outlet tenant aktif yang diproses)
     * @param  array<string, int|string>  $rules  aturan poin (earn_amount, earn_points, redeem_value, min_redeem_points,
     *                                            max_redeem_percent, expiry_months); kosong = tidak diubah
     *
     * @throws ValidationException
     */
    public function handle(bool $enabled, array $enabledOutletIds, array $rules = []): MemberSetting
    {
        $validated = Validator::make($rules, self::ruleValidation())->validate();

        return DB::transaction(function () use ($enabled, $enabledOutletIds, $validated): MemberSetting {
            $setting = MemberSetting::current();
            $setting->fill(['enabled' => $enabled, ...$validated])->save();

            foreach (Outlet::query()->pluck('id') as $outletId) {
                OutletMemberProgram::query()->updateOrCreate(
                    ['outlet_id' => $outletId],
                    ['is_enabled' => in_array($outletId, $enabledOutletIds, true)],
                );
            }

            return $setting;
        });
    }

    /**
     * Aturan bersama form dashboard & Action (ADR 0013, Q62).
     *
     * @return array<string, list<string>>
     */
    public static function ruleValidation(): array
    {
        return [
            'earn_amount' => ['sometimes', 'decimal:0,2', 'min:1', 'max:999999999.99'],
            'earn_points' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'redeem_value' => ['sometimes', 'decimal:0,2', 'min:0', 'max:999999999.99'],
            'min_redeem_points' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'max_redeem_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'expiry_months' => ['sometimes', 'integer', 'min:0', 'max:120'],
        ];
    }
}
