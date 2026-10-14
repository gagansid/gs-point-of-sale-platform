<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Scopes\OutletScope;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
     * Boleh melihat rekap shift ini: pembuka/operator shift (report.view_own), report.view (semua),
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

        // Shift bersama per perangkat (ADR 0017): pembuka atau operator yang pernah bertugas di shift itu
        return $user->can('report.view_own') && $shift->wasOperatedBy($user);
    }

    /**
     * @return array{
     *     type: string,
     *     shift: array{id: string, outlet: string, cashier: string, status: string, opened_at: string, closed_at: string|null, closed_by: string|null, note: string|null,
     *         opening_cash_source: string, opening_cash_note: string|null, opening_cash_adjusted_by: string|null, opening_cash_approved_by: string|null},
     *     sales: array{order_count: int, open_bill_count: int, revenue: string, items_sold: int, discount_total: string, service_total: string, tax_total: string, rounding_total: string, void_count: int, void_total: string},
     *     cash: array{opening_cash: string, carried_cash: string|null, cash_sales: string, cash_in: string, cash_out: string, expected_cash: string, actual_cash: string|null, difference: string|null, cash_deposited: string|null, closing_float: string|null},
     *     operators: list<array{user_id: string, name: string, periods: list<array{started_at: string, ended_at: string|null}>, order_count: int, revenue: string, cash_sales: string}>,
     *     cash_movements: list<array{id: string, type: string, reason: string, reason_label: string, amount: string, note: string|null, user: string, approved_by: string|null, created_at: string}>,
     *     payment_methods: list<array{payment_method_id: string, name: string, category: string, count: int, amount: string}>,
     *     products: list<array{product_id: string, product_name: string, qty: int, revenue: string}>
     * }
     */
    public function for(Shift $shift): array
    {
        $shift->loadMissing(['openedBy:id,name', 'closedBy:id,name', 'outlet:id,name', 'openingCashAdjustedBy:id,name', 'openingCashApprovedBy:id,name']);
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
                // ADR 0017: asal kas awal & koreksinya
                'opening_cash_source' => $shift->opening_cash_source->value,
                'opening_cash_note' => $shift->opening_cash_note,
                'opening_cash_adjusted_by' => $shift->openingCashAdjustedBy?->name,
                'opening_cash_approved_by' => $shift->openingCashApprovedBy?->name,
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
                'carried_cash' => $shift->carried_cash !== null ? Money::fromDb($shift->carried_cash) : null,
                'cash_sales' => $base['cash_sales'],
                'cash_in' => $base['cash_in'],
                'cash_out' => $base['cash_out'],
                // Z-report memakai angka yang dikunci saat tutup shift
                'expected_cash' => $open ? $base['expected_cash'] : Money::fromDb($shift->expected_cash),
                'actual_cash' => $open ? null : Money::fromDb($shift->actual_cash),
                'difference' => $open ? null : Money::fromDb($shift->difference),
                'cash_deposited' => $shift->cash_deposited !== null ? Money::fromDb($shift->cash_deposited) : null,
                'closing_float' => $shift->closing_float !== null ? Money::fromDb($shift->closing_float) : null,
            ],
            'operators' => $this->operators($shift),
            'cash_movements' => $this->movements($shift),
            'payment_methods' => $base['payment_methods'],
            'products' => $this->reports->products($start, $end, null, $filters),
        ];
    }

    /**
     * Rincian per operator (ganti kasir tanpa hitung, ADR 0017): periode bertugas dari log operator, order
     * selesai & tunai diterima per kasir. Kasir yang bertransaksi tanpa baris log (shift lama) tetap muncul.
     *
     * @return list<array{user_id: string, name: string, periods: list<array{started_at: string, ended_at: string|null}>, order_count: int, revenue: string, cash_sales: string}>
     */
    public function operators(Shift $shift): array
    {
        $periods = ShiftOperator::query()->where('shift_id', $shift->id)->orderBy('started_at')->get(['user_id', 'started_at', 'ended_at']);

        $sales = Order::query()->withoutGlobalScope(OutletScope::class)->where('shift_id', $shift->id)->where('status', OrderStatus::Completed)
            ->toBase()->select('user_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('user_id')->get()->keyBy('user_id');
        $cash = Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.shift_id', $shift->id)->where('orders.status', OrderStatus::Completed)
            ->where('payments.status', PaymentStatus::Paid)->where('payments.category', PaymentCategory::Cash)
            ->toBase()->select('orders.user_id', DB::raw('SUM(payments.amount) as cash_sales'))
            ->groupBy('orders.user_id')->pluck('cash_sales', 'user_id');

        $userIds = $periods->pluck('user_id')->merge($sales->keys())->unique()->values();
        $names = User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        return $userIds->map(fn (string $id): array => [
            'user_id' => $id,
            'name' => (string) ($names[$id] ?? '–'),
            'periods' => $periods->where('user_id', $id)->map(fn (ShiftOperator $p): array => [
                'started_at' => $p->started_at->toIso8601ZuluString(),
                'ended_at' => $p->ended_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'order_count' => (int) ($sales[$id]->order_count ?? 0),
            'revenue' => Money::fromDb($sales[$id]->revenue ?? null),
            'cash_sales' => Money::fromDb($cash[$id] ?? null),
        ])->all();
    }

    /** @return list<array{id: string, type: string, reason: string, reason_label: string, amount: string, note: string|null, user: string, approved_by: string|null, created_at: string}> */
    public function movements(Shift $shift): array
    {
        return CashMovement::query()->withoutGlobalScope(OutletScope::class)->where('shift_id', $shift->id)
            ->with(['user:id,name', 'approver:id,name'])->orderBy('created_at')->get()
            ->map(fn (CashMovement $m): array => [
                'id' => (string) $m->id,
                'type' => $m->type->value,
                'reason' => $m->reason->value,
                'reason_label' => $m->reason->getLabel(),
                'amount' => Money::fromDb($m->amount),
                'note' => $m->note,
                'user' => (string) ($m->user->name ?? '–'),
                'approved_by' => $m->approver?->name,
                'created_at' => $m->created_at->toIso8601ZuluString(),
            ])->values()->all();
    }
}
