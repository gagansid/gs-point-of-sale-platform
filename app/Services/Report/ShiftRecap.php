<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Rekap satu shift: X-report (shift berjalan) atau Z-report (sudah ditutup), Q49.
 * Kas & metode bayar dari ShiftSummary (sama dengan tutup shift); produk, diskon, dan void
 * dari ReportService dengan filter shift — satu sumber angka untuk API, dashboard, dan PDF.
 */
final class ShiftRecap
{
    public function __construct(
        private readonly ShiftSummary $summary,
        private readonly ReportService $reports,
    ) {}

    /**
     * Boleh melihat rekap shift ini: pemilik shift (report.view_own), report.view (semua),
     * atau report.view_shift untuk shift dalam batas 31 hari (Q49). Outlet sudah dibatasi
     * OutletScope saat shift dicari (outlet lain → 404).
     */
    public static function canView(User $user, Shift $shift): bool
    {
        if ($user->can('report.view')) {
            return true;
        }

        $timezone = $shift->outlet->timezone ?? CurrentOutlet::timezone();
        if ($user->can('report.view_shift')
            && $shift->opened_at->setTimezone($timezone)->toDateString() >= ReportPeriod::lookbackStart(CarbonImmutable::now($timezone)->toDateString())) {
            return true;
        }

        return $user->can('report.view_own') && $shift->opened_by === $user->id;
    }

    /**
     * @return array{
     *     type: string,
     *     shift: array{id: string, outlet: string, cashier: string, status: string, opened_at: string, closed_at: string|null, closed_by: string|null, note: string|null},
     *     sales: array{order_count: int, open_bill_count: int, revenue: string, items_sold: int, discount_total: string, service_total: string, tax_total: string, rounding_total: string, void_count: int, void_total: string},
     *     cash: array{opening_cash: string, cash_sales: string, expected_cash: string, actual_cash: string|null, difference: string|null},
     *     payment_methods: list<array{payment_method_id: string, name: string, category: string, count: int, amount: string}>,
     *     products: list<array{product_id: string, product_name: string, qty: int, revenue: string}>
     * }
     */
    public function for(Shift $shift): array
    {
        $shift->loadMissing(['openedBy:id,name', 'closedBy:id,name', 'outlet:id,name']);
        $base = $this->summary->for($shift);

        // Rentang lebar + filter shift: order shift ini (termasuk open bill yang dilunasi belakangan)
        $start = $shift->opened_at->subDay();
        $end = CarbonImmutable::now()->addMinute();
        $filters = new ReportFilters(shiftIds: [$shift->id]);
        $totals = $this->reports->summary($start, $end, $filters);
        $open = $shift->status === ShiftStatus::Open;

        return [
            'type' => $open ? 'x_report' : 'z_report',
            'shift' => [
                'id' => (string) $shift->id,
                'outlet' => (string) ($shift->outlet->name ?? ''),
                'cashier' => (string) ($shift->openedBy->name ?? '–'),
                'status' => $shift->status->value,
                'opened_at' => $shift->opened_at->toIso8601ZuluString(),
                'closed_at' => $shift->closed_at?->toIso8601ZuluString(),
                'closed_by' => $shift->closedBy?->name,
                'note' => $shift->close_note,
            ],
            'sales' => [
                'order_count' => $base['order_count'],
                'open_bill_count' => $base['open_bill_count'],
                'revenue' => $base['sales_total'],
                'items_sold' => $totals['items_sold'],
                'discount_total' => $totals['discount_total'],
                'service_total' => $totals['service_total'],
                'tax_total' => $totals['tax_total'],
                'rounding_total' => $totals['rounding_total'],
                'void_count' => $totals['void_count'],
                'void_total' => $totals['void_total'],
            ],
            'cash' => [
                'opening_cash' => Money::fromDb($base['opening_cash']),
                'cash_sales' => $base['cash_sales'],
                // Z-report memakai angka yang dikunci saat tutup shift
                'expected_cash' => $open ? $base['expected_cash'] : Money::fromDb($shift->expected_cash),
                'actual_cash' => $open ? null : Money::fromDb($shift->actual_cash),
                'difference' => $open ? null : Money::fromDb($shift->difference),
            ],
            'payment_methods' => $base['payment_methods'],
            'products' => $this->reports->products($start, $end, null, $filters),
        ];
    }
}
