<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use Illuminate\Support\Facades\DB;

/**
 * Setelan program member (ADR 0013, Q60): aktif di bisnis & outlet yang ikut. Outlet di luar $enabledOutletIds
 * dikecualikan (baris is_enabled = false); tanpa baris = ikut.
 */
final class UpdateMemberProgram
{
    /**
     * @param  list<string>  $enabledOutletIds  outlet yang ikut program (hanya outlet tenant aktif yang diproses)
     */
    public function handle(bool $enabled, array $enabledOutletIds): MemberSetting
    {
        return DB::transaction(function () use ($enabled, $enabledOutletIds): MemberSetting {
            $setting = MemberSetting::current();
            $setting->fill(['enabled' => $enabled])->save();

            foreach (Outlet::query()->pluck('id') as $outletId) {
                OutletMemberProgram::query()->updateOrCreate(
                    ['outlet_id' => $outletId],
                    ['is_enabled' => in_array($outletId, $enabledOutletIds, true)],
                );
            }

            return $setting;
        });
    }
}
