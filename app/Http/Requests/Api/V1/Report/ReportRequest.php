<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Report;

use App\Enums\OrderType;
use App\Enums\ReportTab;
use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportPeriod;
use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rentang laporan dalam tanggal lokal outlet (inklusif). Default: hari ini. Maks. 366 hari.
 * outlet_id opsional (ADR 0010): satu outlet yang dipegang user, atau "all" untuk semua outlet
 * yang dipegang. Tanpa outlet_id: outlet perangkat (bila login di perangkat), selain itu semua.
 * Outlet di luar penugasan → 404.
 *
 * Filter opsional (semua endpoint laporan): order_type, payment_method_ids[], user_ids[],
 * category_ids[], product_ids[], shift_ids[]. compare: previous_period (default) | previous_year | none.
 * granularity (tren): hour | day | week | month, bila cocok dengan rentang (selain itu bawaan).
 *
 * Izin per endpoint (Q49): tab operasional (ringkasan, produk, metode bayar, karyawan, shift, audit)
 * juga untuk report.view_shift — maks. 31 hari ke belakang, di luar itu 422 VALIDATION_ERROR.
 */
final class ReportRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    /** Nama route → jenis laporan untuk izin; tidak terdaftar = hanya report.view. */
    private const ROUTE_TABS = [
        'summary' => ReportTab::Summary,
        'trend' => ReportTab::Summary,
        'products' => ReportTab::Products,
        'payment-methods' => ReportTab::PaymentMethods,
        'employees' => ReportTab::Employees,
        'shifts' => ReportTab::Shifts,
        'audit' => ReportTab::Discounts,
        'categories' => ReportTab::Categories,
        'profit' => ReportTab::Profit,
        'options' => ReportTab::Options,
        'hourly' => ReportTab::Time,
        'heatmap' => ReportTab::Time,
        'stock' => ReportTab::Stock,
        'members' => ReportTab::Members,
        'channels' => ReportTab::OrderTypes,
        'purchases' => ReportTab::Stock,
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $tab = self::ROUTE_TABS[(string) str($this->route()?->getName() ?? '')->afterLast('reports.')] ?? null;
        $permissions = $tab?->permissions() ?? ['report.view'];

        return collect($permissions)->contains(fn (string $permission): bool => $user->can($permission));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'string', 'regex:/^(all|[0-9a-fA-F-]{36})$/'],
            'compare' => ['nullable', Rule::in(array_keys(ReportPeriod::COMPARES))],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'payment_method_ids' => ['nullable', 'array', 'max:50'],
            'payment_method_ids.*' => ['uuid'],
            'user_ids' => ['nullable', 'array', 'max:100'],
            'user_ids.*' => ['uuid'],
            'category_ids' => ['nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid'],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['uuid'],
            'shift_ids' => ['nullable', 'array', 'max:50'],
            'shift_ids.*' => ['uuid'],
            'granularity' => ['nullable', Rule::in(array_keys(ReportPeriod::GRANULARITIES))],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $days = CarbonImmutable::parse($this->from())->diffInDays(CarbonImmutable::parse($this->to())) + 1;
            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Rentang laporan maksimal '.self::MAX_DAYS.' hari');
            }

            // Supervisor (report.view_shift tanpa report.view): maks. 31 hari ke belakang (Q49)
            $user = $this->user();
            $min = ReportContext::minDateFor($user instanceof User ? $user : null);
            if ($min !== null && $this->from() < $min) {
                $validator->errors()->add('from', 'Laporan hanya bisa dilihat maksimal '.ReportPeriod::SUPERVISOR_LOOKBACK_DAYS.' hari ke belakang');
            }
        }];
    }

    /** Mengarahkan CurrentOutlet ke outlet laporan sebelum angka dihitung. */
    protected function passedValidation(): void
    {
        if (! $this->filled('outlet_id')) {
            return;
        }

        $user = $this->user();
        abort_unless($user instanceof User, 403);

        $outletId = $this->string('outlet_id')->toString();
        $outletId = $outletId === 'all' ? null : $outletId;

        if ($outletId !== null && ! $user->canAccessOutlet($outletId)) {
            throw new NotFoundHttpException;
        }

        CurrentOutlet::set($user, $outletId);
    }

    public function from(): string
    {
        return $this->filled('from') ? $this->string('from')->toString() : CurrentOutlet::today();
    }

    public function to(): string
    {
        return $this->filled('to') ? $this->string('to')->toString() : $this->from();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function utcRange(): array
    {
        return CurrentOutlet::utcRange($this->from(), $this->to());
    }

    public function filters(): ReportFilters
    {
        return ReportFilters::fromArray($this->only(['order_type', 'payment_method_ids', 'user_ids', 'category_ids', 'product_ids', 'shift_ids']));
    }

    public function reportPeriod(): ReportPeriod
    {
        return new ReportPeriod($this->from(), $this->to());
    }

    /** Periode pembanding (default periode sebelumnya); null bila compare=none atau di luar batas supervisor. */
    public function comparison(): ?ReportPeriod
    {
        $comparison = $this->reportPeriod()->comparison($this->filled('compare') ? $this->string('compare')->toString() : 'previous_period');
        $user = $this->user();
        $min = ReportContext::minDateFor($user instanceof User ? $user : null);

        return $comparison !== null && $min !== null && $comparison->from < $min ? null : $comparison;
    }

    public function granularity(): string
    {
        return $this->reportPeriod()->granularity($this->filled('granularity') ? $this->string('granularity')->toString() : null);
    }

    /** @return array{from: string, to: string, timezone: string, outlet_id: string|null} */
    public function period(): array
    {
        return ['from' => $this->from(), 'to' => $this->to(), 'timezone' => CurrentOutlet::timezone(), 'outlet_id' => CurrentOutlet::selectedId()];
    }
}
