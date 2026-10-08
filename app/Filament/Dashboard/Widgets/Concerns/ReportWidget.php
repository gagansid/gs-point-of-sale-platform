<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Concerns;

use App\Models\User;
use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;

/**
 * Bersama untuk widget beranda: hanya untuk yang punya report.view, rentang hari lokal outlet.
 */
trait ReportWidget
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('report.view');
    }

    /**
     * Rentang N hari terakhir (termasuk hari ini) dalam UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function lastDays(int $days): array
    {
        $today = CurrentOutlet::today();
        $from = CarbonImmutable::parse($today)->subDays($days - 1)->toDateString();

        return CurrentOutlet::utcRange($from, $today);
    }
}
