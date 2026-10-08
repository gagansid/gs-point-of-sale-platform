<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Option;
use App\Models\OptionGroup;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus grup opsi (soft delete): dilepas dari semua produk, opsinya ikut dihapus.
 */
final class DeleteOptionGroup
{
    public function handle(OptionGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->products()->detach();
            Option::query()->where('option_group_id', $group->id)->delete();
            $group->delete();
        });
    }
}
