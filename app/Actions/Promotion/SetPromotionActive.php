<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Models\Promotion;

/** Aktifkan / nonaktifkan promo otomatis (ADR 0022; izin promo.manage). */
final class SetPromotionActive
{
    public function handle(Promotion $promotion, bool $active): Promotion
    {
        $promotion->forceFill(['is_active' => $active])->save();

        return $promotion;
    }
}
