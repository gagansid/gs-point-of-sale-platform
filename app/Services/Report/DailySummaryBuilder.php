<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Isi ringkasan harian email (Q57, R5) untuk satu user: outlet aktif yang boleh ia akses, satu tanggal lokal
 * (rentang dihitung per zona waktu outlet). Dipanggil di dalam TenantContext tenant user; outlet tetap
 * dibatasi eksplisit (command tidak punya konteks outlet).
 *
 * Omzet = grand_total order selesai (completed_at), void berdasarkan voided_at, selisih kas dari shift yang
 * ditutup pada tanggal itu — sama dengan ReportService.
 */
final class DailySummaryBuilder
{
    /**
     * @return array{date: string, has_activity: bool, outlets: list<array{name: string, order_count: int, revenue: string, average: string,
     *     void_count: int, void_total: string, shift_count: int, cash_difference: string, target: string|null, target_percent: string|null}>,
     *     totals: array{order_count: int, revenue: string, void_count: int, void_total: string, cash_difference: string}}
     */
    public function build(User $user, string $date): array
    {
        $outlets = $user->accessibleOutlets()->where('is_active', true)->reorder('name')->get();

        $rows = $outlets->map(function (Outlet $outlet) use ($date): array {
            $start = CarbonImmutable::parse($date, $outlet->timezone)->startOfDay()->utc();
            $end = CarbonImmutable::parse($date, $outlet->timezone)->endOfDay()->utc();

            $sales = Order::query()->where('outlet_id', $outlet->id)->where('status', OrderStatus::Completed)
                ->whereBetween('completed_at', [$start, $end])->toBase()
                ->selectRaw('COUNT(*) as order_count, SUM(grand_total) as revenue')->first();
            $voids = Order::query()->where('outlet_id', $outlet->id)->where('status', OrderStatus::Voided)
                ->whereBetween('voided_at', [$start, $end])->toBase()
                ->selectRaw('COUNT(*) as void_count, SUM(grand_total) as void_total')->first();
            $shifts = Shift::query()->where('outlet_id', $outlet->id)
                ->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])
                ->whereBetween('closed_at', [$start, $end])->toBase()
                ->selectRaw('COUNT(*) as shift_count, SUM(difference) as difference')->first();

            $count = (int) ($sales->order_count ?? 0);
            $revenue = Money::fromDb($sales->revenue ?? null);
            $target = $outlet->daily_sales_target;

            return [
                'name' => $outlet->name,
                'order_count' => $count,
                'revenue' => $revenue,
                'average' => Money::div($revenue, $count),
                'void_count' => (int) ($voids->void_count ?? 0),
                'void_total' => Money::fromDb($voids->void_total ?? null),
                'shift_count' => (int) ($shifts->shift_count ?? 0),
                'cash_difference' => Money::fromDb($shifts->difference ?? null),
                'target' => $target,
                'target_percent' => $target !== null && Money::isPositive($target)
                    ? Money::round(bcdiv(bcmul($revenue, '100', 4), $target, 4), 1)
                    : null,
            ];
        })->values()->all();

        $sum = fn (string $key): string => Money::add('0', ...array_column($rows, $key));

        return [
            'date' => $date,
            'has_activity' => collect($rows)->contains(fn (array $r): bool => $r['order_count'] > 0 || $r['void_count'] > 0 || $r['shift_count'] > 0),
            'outlets' => $rows,
            'totals' => [
                'order_count' => (int) array_sum(array_column($rows, 'order_count')),
                'revenue' => $sum('revenue'),
                'void_count' => (int) array_sum(array_column($rows, 'void_count')),
                'void_total' => $sum('void_total'),
                'cash_difference' => $sum('cash_difference'),
            ],
        ];
    }
}
