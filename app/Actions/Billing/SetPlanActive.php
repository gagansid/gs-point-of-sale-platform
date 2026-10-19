<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Support\Features;
use Illuminate\Support\Facades\Log;

/**
 * Aktifkan/nonaktifkan paket dari daftar Paket (/admin). Nonaktif: tidak bisa dipilih untuk tagihan baru; bisnis yang
 * sudah memakai paket ini tetap berjalan sampai akhir masa aktif.
 */
final class SetPlanActive
{
    public function handle(Plan $plan, bool $active): Plan
    {
        $plan->forceFill(['is_active' => $active])->save();
        Features::flush();
        Log::info('Status paket diubah', ['plan_id' => $plan->id, 'is_active' => $active]);

        return $plan;
    }
}
