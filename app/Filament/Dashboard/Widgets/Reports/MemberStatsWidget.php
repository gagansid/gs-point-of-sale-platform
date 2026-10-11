<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Laporan › Member (ADR 0013, M7): member vs non-member, member aktif/berulang/baru, poin & utang poin, voucher,
 * kartu stempel.
 */
final class MemberStatsWidget extends StatsOverviewWidget
{
    use ReportWidget;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 2, 'md' => 4];
    }

    protected function getStats(): array
    {
        [$start, $end] = $this->context()->range();
        $s = app(ReportService::class)->members($start, $end, $this->context()->filters)['summary'];
        $count = fn (int $n): string => number_format($n, 0, ',', '.');

        return [
            Stat::make('Omzet member', Money::format($s['member_revenue']))
                ->description($s['member_share'] !== null ? str_replace('.', ',', $s['member_share']).'% dari omzet · '.$count($s['member_orders']).' transaksi' : $count($s['member_orders']).' transaksi')
                ->icon(Heroicon::OutlinedUserGroup),
            Stat::make('Non-member', Money::format($s['guest_revenue']))
                ->description($count($s['guest_orders']).' transaksi')->icon(Heroicon::OutlinedUser),
            Stat::make('Member aktif', $count($s['active_members']))
                ->description($count($s['repeat_members']).' datang ≥ 2 kali')->icon(Heroicon::OutlinedArrowPath),
            Stat::make('Member baru', $count($s['new_members']))
                ->description('Mendaftar di periode ini')->icon(Heroicon::OutlinedUserPlus),
            Stat::make('Poin', $count($s['points_earned']).' didapat')
                ->description($count($s['points_redeemed']).' ditukar · '.$count($s['points_expired']).' kedaluwarsa')->icon(Heroicon::OutlinedStar),
            Stat::make('Utang poin', Money::format($s['points_liability_value']))
                ->description($count($s['points_liability']).' poin aktif (saat ini, semua outlet)')->icon(Heroicon::OutlinedScale),
            Stat::make('Voucher', Money::format($s['voucher_discount']))
                ->description($count($s['voucher_uses']).' kali dipakai')->icon(Heroicon::OutlinedReceiptPercent),
            Stat::make('Gratis stempel', Money::format($s['stamp_free_total']))
                ->description($count($s['stamp_free_count']).' item gratis · diskon member '.Money::format($s['member_discount']))->icon(Heroicon::OutlinedTicket),
        ];
    }
}
