<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Addon;
use App\Support\Features;
use Illuminate\Support\Facades\Log;

/**
 * Aktifkan/nonaktifkan add-on dari daftar Add-on (/admin). Nonaktif: tidak bisa dibeli/ditagihkan lagi; bisnis yang
 * sudah memilikinya tetap memakai sampai berhenti.
 */
final class SetAddonActive
{
    public function handle(Addon $addon, bool $active): Addon
    {
        $addon->forceFill(['is_active' => $active])->save();
        Features::flush();
        Log::info('Status add-on diubah', ['addon_id' => $addon->id, 'is_active' => $active]);

        return $addon;
    }
}
