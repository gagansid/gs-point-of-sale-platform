<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\OptionGroup;
use Illuminate\Support\Facades\DB;

/**
 * Aktifkan/nonaktifkan. Yang nonaktif tidak dikirim ke aplikasi kasir dan ditolak saat checkout (SPEC Q27).
 */
final class SetOptionGroupActive
{
    public function handle(OptionGroup $group, bool $active): OptionGroup
    {
        DB::transaction(fn () => $group->forceFill(['is_active' => $active])->save());

        return $group;
    }
}
