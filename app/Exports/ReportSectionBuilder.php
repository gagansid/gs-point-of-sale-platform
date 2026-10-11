<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\PaymentCategory;
use App\Enums\ReportTab;
use App\Enums\ShiftStatus;
use App\Exceptions\ReportTooLargeException;
use App\Filament\Dashboard\Widgets\Reports\HeatmapWidget;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportPeriod;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data export per jenis laporan (ReportTab) → daftar ReportSection. Satu sumber angka untuk
 * Excel & PDF (ReportService, filter & batas supervisor dari ReportContext). Tabel detail
 * (void, diskon, selisih kas) dibaca dengan cursor(). Lebih dari $maxRows baris → ditolak.
 */
final class ReportSectionBuilder
{
    private int $rowCount = 0;

    private int $maxRows = PHP_INT_MAX;

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  list<ReportTab>  $tabs
     * @return list<ReportSection>
     *
     * @throws ReportTooLargeException
     */
    public function build(array $tabs, ReportContext $context, int $maxRows): array
    {
        $this->rowCount = 0;
        $this->maxRows = $maxRows;
        $sections = [];

        foreach ($tabs as $tab) {
            foreach ($this->sectionsFor($tab, $context) as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /** @return list<ReportSection> */
    private function sectionsFor(ReportTab $tab, ReportContext $context): array
    {
        [$start, $end] = $context->range();
        $filters = $context->filters;
        $timezone = CurrentOutlet::timezone();
        $count = fn (int $n): int => $n;

        return match ($tab) {
            ReportTab::Summary => (function () use ($start, $end, $filters, $timezone, $context): array {
                $s = $this->reports->summary($start, $end, $filters);
                $trend = $this->reports->trend($start, $end, $timezone, $context->granularity, $filters);

                return [
                    $this->section('Ringkasan', ['Keterangan', 'Nilai'], [
                        ['Transaksi selesai', $s['order_count']],
                        ['Item terjual', $s['items_sold']],
                        ['Penjualan bruto', $s['subtotal_total']],
                        ['Diskon', $s['discount_total']],
                        ['Service charge', $s['service_total']],
                        ['Pajak', $s['tax_total']],
                        ['Pembulatan', $s['rounding_total']],
                        ['Omzet diterima', $s['revenue']],
                        ['Penjualan bersih (tanpa pajak, service & pembulatan)', $s['net_sales']],
                        ['Rata-rata transaksi', $s['average']],
                        ['Transaksi void', $s['void_count']],
                        ['Nilai void', $s['void_total']],
                    ], moneyRows: true),
                    $this->section('Tren '.mb_strtolower(ReportPeriod::GRANULARITIES[$context->granularity]), ['Periode', 'Transaksi', 'Omzet'],
                        array_map(fn (array $r): array => [$r['label'].($r['from'] !== $r['to'] ? " ({$r['from']} – {$r['to']})" : ''), $r['order_count'], $r['revenue']], $trend),
                        [2], ['Total', array_sum(array_column($trend, 'order_count')), Money::add('0', ...array_column($trend, 'revenue'))]),
                ];
            })(),
            ReportTab::Products => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->products($start, $end, null, $filters);
                $total = Money::add('0', ...array_column($rows, 'revenue'));

                return [$this->section('Per produk', ['Produk', 'Qty', 'Omzet', 'Kontribusi (%)'],
                    array_map(fn (array $r): array => [$r['product_name'], $r['qty'], $r['revenue'], self::share($r['revenue'], $total)], $rows),
                    [2], ['Total', array_sum(array_column($rows, 'qty')), $total, '100.0'])];
            })(),
            ReportTab::Categories => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->categories($start, $end, $filters);
                $total = Money::add('0', ...array_column($rows, 'revenue'));

                return [$this->section('Per kategori', ['Kategori', 'Produk', 'Qty', 'Omzet', 'Kontribusi (%)'],
                    array_map(fn (array $r): array => [$r['category_name'], $r['product_count'], $r['qty'], $r['revenue'], self::share($r['revenue'], $total)], $rows),
                    [3], ['Total', null, array_sum(array_column($rows, 'qty')), $total, '100.0'])];
            })(),
            ReportTab::Profit => (function () use ($start, $end, $filters): array {
                $profit = $this->reports->profit($start, $end, $filters);
                $t = $profit['totals'];

                return [$this->section('Laba kotor per produk', ['Produk', 'Kategori', 'Qty', 'Penjualan bersih', 'HPP', 'Laba kotor', 'Margin (%)', 'Item tanpa harga modal'],
                    array_map(fn (array $r): array => [$r['product_name'], $r['category_name'], $r['qty'], $r['net_sales'], $r['cogs'], $r['gross_profit'], $r['margin'], $r['uncosted_qty']], $profit['products']),
                    [3, 4, 5], ['Total', null, array_sum(array_column($profit['products'], 'qty')), $t['net_sales'], $t['cogs'], $t['gross_profit'], $t['margin'], $t['uncosted_qty']])];
            })(),
            ReportTab::Options => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->options($start, $end, $filters);

                return [$this->section('Opsi & add-on', ['Opsi', 'Dipilih', 'Tambahan omzet'],
                    array_map(fn (array $r): array => [$r['option_name'], $r['qty'], $r['revenue']], $rows),
                    [2], ['Total', array_sum(array_column($rows, 'qty')), Money::add('0', ...array_column($rows, 'revenue'))])];
            })(),
            ReportTab::PaymentMethods => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->paymentMethods($start, $end, $filters);

                return [$this->section('Per metode bayar', ['Metode', 'Kategori', 'Transaksi', 'Nominal'],
                    array_map(fn (array $r): array => [$r['name'], PaymentCategory::tryFrom($r['category'])?->getLabel() ?? $r['category'], $r['count'], $r['amount']], $rows),
                    [3], ['Total', null, array_sum(array_column($rows, 'count')), Money::add('0', ...array_column($rows, 'amount'))])];
            })(),
            ReportTab::Employees => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->employees($start, $end, $filters);

                return [$this->section('Kinerja karyawan', ['Kasir', 'Transaksi', 'Omzet', 'Rata-rata', 'Diskon', 'Void', 'Nilai void'],
                    array_map(fn (array $r): array => [$r['name'], $r['order_count'], $r['revenue'], $r['average'], $r['discount_total'], $r['void_count'], $r['void_total']], $rows),
                    [2, 3, 4, 6])];
            })(),
            ReportTab::Shifts => (function () use ($start, $end, $filters, $timezone): array {
                $rows = $this->reports->shifts($start, $end, $filters);
                $time = fn (?string $iso): string => $iso === null ? '-' : CarbonImmutable::parse($iso)->setTimezone($timezone)->format('Y-m-d H:i');

                return [$this->section('Rekap shift', ['Kasir', 'Outlet', 'Buka', 'Tutup', 'Transaksi', 'Penjualan', 'Kas awal', 'Tunai', 'Seharusnya', 'Aktual', 'Selisih', 'Status'],
                    array_map(fn (array $r): array => [
                        $r['cashier'], $r['outlet'], $time($r['opened_at']), $time($r['closed_at']), $r['order_count'], $r['sales_total'],
                        $r['opening_cash'], $r['cash_sales'], $r['expected_cash'], $r['actual_cash'], $r['difference'], ShiftStatus::from($r['status'])->getLabel(),
                    ], $rows),
                    [5, 6, 7, 8, 9, 10])];
            })(),
            ReportTab::Time => (function () use ($start, $end, $filters, $timezone): array {
                $hours = $this->reports->hourly($start, $end, $timezone, $filters);
                $grid = $this->reports->heatmap($start, $end, $timezone, $filters);

                return [
                    $this->section('Per jam', ['Jam', 'Transaksi', 'Omzet'],
                        array_map(fn (array $h): array => [sprintf('%02d.00', $h['hour']), $h['order_count'], $h['revenue']], $hours), [2]),
                    $this->section('Hari × jam (transaksi)', ['Hari', ...array_map(fn (int $h): string => sprintf('%02d', $h), range(0, 23))],
                        array_map(fn (int $day): array => [HeatmapWidget::DAYS[$day], ...array_values($grid[$day])], range(1, 7))),
                ];
            })(),
            ReportTab::OrderTypes => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->orderTypes($start, $end, $filters);

                return [$this->section('Per tipe order', ['Tipe', 'Transaksi', 'Omzet'],
                    array_map(fn (array $r): array => [$r['label'], $r['order_count'], $r['revenue']], $rows), [2])];
            })(),
            ReportTab::Outlets => (function () use ($start, $end, $filters): array {
                $rows = $this->reports->outlets($start, $end, $filters);

                return [$this->section('Per outlet', ['Outlet', 'Transaksi', 'Omzet', 'Rata-rata', 'Void', 'Nilai void'],
                    array_map(fn (array $r): array => [$r['name'], $r['order_count'], $r['revenue'], $r['average'], $r['void_count'], $r['void_total']], $rows),
                    [2, 3, 5])];
            })(),
            ReportTab::Discounts => $this->auditSections($context, $timezone),
            ReportTab::Members => (function () use ($start, $end, $filters): array {
                $m = $this->reports->members($start, $end, $filters);
                $s = $m['summary'];

                return [
                    $this->section('Ringkasan member', ['Keterangan', 'Nilai'], [
                        ['Transaksi member', $s['member_orders']],
                        ['Omzet member', $s['member_revenue']],
                        ['Transaksi non-member', $s['guest_orders']],
                        ['Omzet non-member', $s['guest_revenue']],
                        ['Member aktif', $s['active_members']],
                        ['Member datang ≥ 2 kali', $s['repeat_members']],
                        ['Member baru', $s['new_members']],
                        ['Diskon member', $s['member_discount']],
                        ['Poin didapat', $s['points_earned']],
                        ['Poin ditukar', $s['points_redeemed']],
                        ['Poin kedaluwarsa', $s['points_expired']],
                        ['Utang poin (poin aktif saat ini)', $s['points_liability']],
                        ['Utang poin (nilai)', $s['points_liability_value']],
                        ['Voucher dipakai', $s['voucher_uses']],
                        ['Potongan voucher', $s['voucher_discount']],
                        ['Item gratis stempel', $s['stamp_free_count']],
                        ['Nilai gratis stempel', $s['stamp_free_total']],
                    ], moneyRows: true),
                    $this->section('Member teratas', ['Member', 'Tier', 'Transaksi', 'Belanja', 'Poin didapat'],
                        array_map(fn (array $r): array => [$r['member_name'], $r['tier'] ?? '-', $r['order_count'], $r['revenue'], $r['points_earned']], $m['top_members']),
                        [3]),
                    $this->section('Pemakaian voucher', ['Voucher', 'Dipakai', 'Potongan'],
                        array_map(fn (array $r): array => [$r['voucher_code'], $r['uses'], $r['discount']], $m['vouchers']),
                        [2], ['Total', array_sum(array_column($m['vouchers'], 'uses')), Money::add('0', ...array_column($m['vouchers'], 'discount'))]),
                ];
            })(),
            ReportTab::Stock => (function () use ($start, $end, $filters, $count): array {
                $rows = $this->reports->stock($start, $end, $filters);

                return [$this->section('Pergerakan stok', ['Produk', 'Terjual', 'Kembali (void)', 'Penyesuaian', 'Perubahan', 'Stok kini', 'Status'],
                    array_map(fn (array $r): array => [
                        $r['product_name'], $count($r['sold']), $r['void_return'], $r['adjustment'], $r['net_change'],
                        $r['stock'] ?? '-', $r['stock'] === null ? 'Tidak dilacak' : ($r['is_low'] ? 'Habis/menipis' : 'Aman'),
                    ], $rows))];
            })(),
        };
    }

    /** @return list<ReportSection> */
    private function auditSections(ReportContext $context, string $timezone): array
    {
        [$start, $end] = $context->range();
        $time = fn (?CarbonImmutable $at): string => $at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '-';

        $voids = [];
        foreach ($this->reports->voidQuery($start, $end, $context->filters)->with(['user:id,name', 'voidedBy:id,name', 'voidApprovedBy:id,name'])->orderBy('voided_at')->cursor() as $order) {
            /** @var Order $order */
            $this->guard();
            $voids[] = [$order->order_number, $time($order->voided_at), $order->grand_total, $order->user->name ?? '-', $order->voidedBy->name ?? '-', $order->voidApprovedBy->name ?? '-', (string) $order->void_reason];
        }

        $discounts = [];
        foreach ($this->reports->approvedDiscountQuery($start, $end, $context->filters)->with(['user:id,name', 'approvedBy:id,name'])->orderBy('completed_at')->cursor() as $order) {
            /** @var Order $order */
            $this->guard();
            $discounts[] = [$order->order_number, $time($order->completed_at), $order->user->name ?? '-', $order->approvedBy->name ?? '-', $order->discount_total, $order->grand_total];
        }

        $shifts = [];
        $userIds = $context->filters->userIds;
        $query = CurrentOutlet::scope(Shift::query())
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])
            ->whereBetween('closed_at', [$start, $end])
            ->where(fn (Builder $q) => $q->where('difference', '!=', 0)->orWhere('status', ShiftStatus::ForceClosed))
            ->when($userIds !== [], fn (Builder $q) => $q->whereIn('opened_by', $userIds))
            ->orderBy('closed_at');
        foreach ($query->cursor() as $shift) {
            /** @var Shift $shift */
            $this->guard();
            $shifts[] = [$shift->openedBy->name ?? '-', $time($shift->closed_at), Money::fromDb($shift->expected_cash), Money::fromDb($shift->actual_cash), Money::fromDb($shift->difference), $shift->status->getLabel(), $shift->closedBy->name ?? '-', (string) $shift->close_note];
        }

        return [
            new ReportSection('Transaksi void', ['No. order', 'Waktu', 'Nominal', 'Kasir', 'Di-void oleh', 'Disetujui', 'Alasan'], $voids, [2],
                ['Total', count($voids).' transaksi', Money::add('0', ...array_column($voids, 2)), null, null, null, null]),
            new ReportSection('Diskon dengan approval PIN', ['No. order', 'Waktu', 'Kasir', 'Disetujui', 'Diskon', 'Total order'], $discounts, [4, 5],
                ['Total', count($discounts).' transaksi', null, null, Money::add('0', ...array_column($discounts, 4)), null]),
            new ReportSection('Selisih kas & tutup paksa', ['Kasir', 'Ditutup', 'Seharusnya', 'Aktual', 'Selisih', 'Status', 'Ditutup oleh', 'Catatan'], $shifts, [2, 3, 4],
                ['Total', count($shifts).' shift', null, null, Money::add('0', ...array_column($shifts, 4)), null, null, null]),
        ];
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|null>>  $rows
     * @param  list<int>  $moneyColumns
     * @param  list<string|int|null>|null  $totals
     */
    private function section(string $title, array $headings, array $rows, array $moneyColumns = [], ?array $totals = null, bool $moneyRows = false): ReportSection
    {
        foreach ($rows as $_) {
            $this->guard();
        }

        // Ringkasan: kolom "Nilai" campuran angka & nominal → nominal ditandai per baris (string desimal)
        if ($moneyRows) {
            return new ReportSection($title, $headings, $rows, [1]);
        }

        return new ReportSection($title, $headings, $rows, $moneyColumns, $totals);
    }

    private function guard(): void
    {
        if (++$this->rowCount > $this->maxRows) {
            throw new ReportTooLargeException($this->rowCount, $this->maxRows);
        }
    }

    private static function share(string $value, string $total): string
    {
        return Money::compare($total, '0') > 0 ? Money::round(bcdiv(bcmul($value, '100', 4), $total, 4), 1) : '0.0';
    }
}
