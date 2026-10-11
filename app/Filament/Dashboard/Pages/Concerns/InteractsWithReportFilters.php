<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Concerns;

use App\Enums\OrderType;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\CachedReportService;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportFilterLabels;
use App\Services\Report\ReportPeriod;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Filter laporan bersama (Beranda & halaman Laporan): field form, normalisasi state (preset relatif
 * hari ini, batas 31 hari supervisor — Q49), ganti outlet = ganti pemilih sidebar, subjudul periode.
 * State di $filters (session) + parameter ringkas di URL (SyncsReportFiltersToUrl).
 */
trait InteractsWithReportFilters
{
    use SyncsReportFiltersToUrl;

    /** Jam hitung terakhir (zona outlet), tampil di subjudul. */
    public string $refreshedAt = '';

    /** Preset bawaan halaman (Beranda: hari ini; Laporan menimpa ke bulan ini). */
    protected function defaultReportPreset(): string
    {
        return 'today';
    }

    protected function reportUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function reportContext(): ReportContext
    {
        return ReportContext::fromInput($this->filters ?? [], $this->reportUser());
    }

    /** Dipanggil dari mountHasFilters halaman: session/URL → state aman. */
    protected function mountReportFilters(): void
    {
        $input = $this->filters ?? [];
        $this->fillReportFilters($this->normalizedReportFilters($this->hasReportQuery() ? $this->filtersWithUrl($input) : $input));
        session()->put($this->getFiltersSessionKey(), $this->filters);
        $this->syncUrlFromFilters($this->filters);
        $this->touchRefreshedAt();
    }

    /** Dipanggil dari updatedFilters() halaman (nama bawaan HasFilters tidak bisa ditimpa trait). */
    protected function reportFiltersUpdated(): void
    {
        // Granularitas yang tidak cocok lagi dengan rentang baru kembali ke bawaan
        $this->filters = $this->normalizedReportFilters($this->filters ?? [], keepDates: true);
        $this->touchRefreshedAt();

        session()->put($this->getFiltersSessionKey(), $this->filters);
        $this->syncUrlFromFilters($this->filters);
    }

    protected function resetReportFilters(): void
    {
        $this->fillReportFilters($this->normalizedReportFilters([]));
        $this->reportFiltersUpdated();
    }

    /**
     * Mengisi form toolbar lalu mengembalikan state lengkap: fill() hanya menyimpan field yang ada
     * di form, padahal filter lanjutan (panel samping) juga disimpan di $filters.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function fillReportFilters(array $filters): void
    {
        $this->getFiltersForm()->fill($filters);
        $this->filters = [...$filters, ...($this->filters ?? [])];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizedReportFilters(array $input, bool $keepDates = false): array
    {
        $presets = $this->presetOptions();
        $default = isset($presets[$this->defaultReportPreset()]) ? $this->defaultReportPreset() : 'today';
        $preset = is_string($input['preset'] ?? null) && isset($presets[$input['preset']]) ? $input['preset'] : $default;
        $context = ReportContext::fromInput([...$input, 'preset' => $preset], $this->reportUser());

        return [
            ...$input,
            // Sumber kebenaran outlet = pemilih sidebar (session), bukan filter tersimpan
            'outlet' => CurrentOutlet::selectedId() ?? 'all',
            'preset' => $preset,
            // State DatePicker boleh berjam ("Y-m-d H:i:s"); saat mengetik, jangan menimpa tanggal valid
            'from' => $keepDates && substr((string) ($input['from'] ?? ''), 0, 10) === $context->period->from ? $input['from'] : $context->period->from,
            'to' => $keepDates && substr((string) ($input['to'] ?? ''), 0, 10) === $context->period->to ? $input['to'] : $context->period->to,
            'compare' => is_string($input['compare'] ?? null) && isset(ReportPeriod::COMPARES[$input['compare']]) ? $input['compare'] : 'previous_period',
            'granularity' => $context->granularity,
            'granularity_default' => $context->period->defaultGranularity(),
            'order_type' => $context->filters->orderType?->value,
            'payment_method_ids' => $context->filters->paymentMethodIds,
            'user_ids' => $context->filters->userIds,
            'category_ids' => $context->filters->categoryIds,
            'product_ids' => $context->filters->productIds,
            'shift_ids' => $context->filters->shiftIds,
        ];
    }

    /**
     * Preset yang boleh dipakai user: supervisor hanya preset yang seluruhnya dalam 31 hari terakhir.
     *
     * @return array<string, string>
     */
    protected function presetOptions(): array
    {
        $today = CurrentOutlet::today();
        $min = ReportContext::minDateFor($this->reportUser(), $today);

        if ($min === null) {
            return ReportPeriod::PRESETS;
        }

        return array_filter(
            ReportPeriod::PRESETS,
            fn (string $label, string $key): bool => $key === 'custom' || ReportPeriod::preset($key, $today)->from >= $min,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    protected function reportSubheading(): string
    {
        $context = $this->reportContext();
        $parts = [$context->period->label()];

        if ($context->comparison !== null) {
            $parts[] = 'dibanding '.$context->comparison->label();
        }

        if ($context->minDate !== null) {
            $parts[] = 'maks. '.ReportPeriod::SUPERVISOR_LOOKBACK_DAYS.' hari terakhir';
        }

        if ($this->refreshedAt !== '') {
            $parts[] = 'diperbarui '.$this->refreshedAt;
        }

        return implode(' · ', $parts);
    }

    protected function touchRefreshedAt(): void
    {
        $this->refreshedAt = now(CurrentOutlet::timezone())->format('H.i');
    }

    protected function refreshReportAction(): Action
    {
        return Action::make('refresh')
            ->label('Muat ulang')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                // Angka terbaru, bukan dari cache 60 detik (R2)
                if (($tenantId = TenantContext::id()) !== null) {
                    CachedReportService::flush($tenantId);
                }

                $this->touchRefreshedAt();
                $this->dispatch('dashboard-refresh');
            });
    }

    protected function resetReportFiltersAction(): Action
    {
        return Action::make('resetFilters')
            ->label('Reset filter')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->action(fn () => $this->resetReportFilters());
    }

    /** Class halaman untuk tata letak laporan yang lebih rapat (home.css). */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'pos-report-page'];
    }

    /**
     * Field filter lanjutan halaman (tipe order, metode bayar, kasir, …) — tampil di panel samping.
     *
     * @return list<Component>
     */
    abstract protected function advancedFilterFields(): array;

    /**
     * Ringkasan filter lanjutan yang aktif (badge di bawah toolbar).
     *
     * @return list<string>
     */
    public function activeFilterPills(): array
    {
        return ReportFilterLabels::describe($this->reportContext()->filters);
    }

    /** Jumlah kelompok filter lanjutan yang aktif (badge tombol). */
    public function activeAdvancedFilterCount(): int
    {
        return count($this->reportContext()->filters->toArray());
    }

    /**
     * Tombol "Filter lanjutan" → panel samping. Nilai diterapkan ke $filters (session + URL) saat
     * "Terapkan"; "Hapus filter" mengosongkan filter lanjutan saja (periode tetap).
     */
    public function advancedFiltersAction(): Action
    {
        $keys = ['order_type', 'payment_method_ids', 'user_ids', 'category_ids', 'product_ids', 'shift_ids'];

        return Action::make('advancedFilters')
            ->label('Filter lanjutan')
            ->icon(Heroicon::OutlinedFunnel)
            ->color('gray')
            // Ukuran standar (32px) sama dengan field & tombol Simpan/Batal
            ->badge(fn (): ?int => $this->activeAdvancedFilterCount() ?: null)
            ->slideOver()
            ->modalWidth('md')
            ->modalHeading('Filter lanjutan')
            ->modalDescription('Persempit laporan berdasarkan tipe order, pembayaran, kasir, atau produk.')
            ->modalSubmitActionLabel('Terapkan')
            ->fillForm(fn (): array => array_intersect_key($this->filters ?? [], array_flip($keys)))
            ->schema(fn (): array => $this->advancedFilterFields())
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('clearAdvancedFilters', arguments: ['clear' => true])
                    ->label('Hapus filter')
                    ->color('gray'),
            ])
            ->action(function (array $data, array $arguments) use ($keys): void {
                $values = ($arguments['clear'] ?? false) ? array_fill_keys($keys, null) : array_intersect_key($data, array_flip($keys));
                $this->filters = [...($this->filters ?? []), ...$values];
                $this->updatedFilters();
            });
    }

    // ---------- Field filter ----------

    protected function outletField(): Select
    {
        $outlets = $this->reportUser()?->accessibleOutlets()->pluck('name', 'id')->all() ?? [];

        return Select::make('outlet')
            ->label('Outlet')
            ->options(['all' => 'Semua outlet', ...$outlets])
            ->selectablePlaceholder(false)
            ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
            ->visible(count($outlets) > 1)
            ->afterStateUpdated(fn (?string $state) => $this->switchReportOutlet($state));
    }

    protected function presetField(): Select
    {
        return Select::make('preset')
            ->label('Periode')
            ->options(fn (): array => $this->presetOptions())
            ->selectablePlaceholder(false)
            ->afterStateUpdated(function (?string $state, Set $set): void {
                if ($state === null || $state === 'custom') {
                    return;
                }

                $period = ReportPeriod::preset($state, CurrentOutlet::today());
                $set('from', $period->from);
                $set('to', $period->to);
            });
    }

    /**
     * DatePicker periode: ikon kalender di kanan membuka popup kalender (JS di browser saja).
     * Mengubah tanggal = preset kustom. Supervisor: tidak bisa memilih sebelum batas 31 hari.
     */
    protected function dateField(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)
            ->label($label)
            ->native(false)
            ->displayFormat('j M Y')
            ->closeOnDateSelection()
            ->minDate(fn (): ?string => ReportContext::minDateFor($this->reportUser()))
            ->maxDate(fn (): string => CurrentOutlet::today())
            ->suffixAction(
                Action::make("open_{$name}_calendar")
                    ->label('Buka kalender')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->alpineClickHandler(<<<'JS'
                        $el.closest('.fi-input-wrp').querySelector('input[x-ref=button]')?.click()
                    JS),
            )
            ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom'));
    }

    protected function compareField(): Select
    {
        return Select::make('compare')
            ->label('Bandingkan dengan')
            ->options(ReportPeriod::COMPARES)
            ->selectablePlaceholder(false);
    }

    /** Pilihan granularitas mengikuti rentang yang sedang dipilih. */
    protected function granularityField(): Select
    {
        return Select::make('granularity')
            ->label('Kelompokkan')
            ->options(function (Get $get): array {
                $period = ReportContext::fromInput([
                    'preset' => $get('preset'), 'from' => $get('from'), 'to' => $get('to'),
                ], $this->reportUser())->period;

                return array_intersect_key(ReportPeriod::GRANULARITIES, array_flip($period->allowedGranularities()));
            })
            ->selectablePlaceholder(false);
    }

    protected function orderTypeField(): Select
    {
        return Select::make('order_type')->label('Tipe order')->options(OrderType::class)->placeholder('Semua tipe');
    }

    protected function paymentMethodField(): Select
    {
        return Select::make('payment_method_ids')
            ->label('Metode bayar')
            ->multiple()
            ->options(fn (): array => PaymentMethod::query()->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('Semua metode');
    }

    protected function cashierField(): Select
    {
        return Select::make('user_ids')
            ->label('Kasir')
            ->multiple()
            ->searchable()
            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('Semua kasir');
    }

    protected function categoryField(): Select
    {
        return Select::make('category_ids')
            ->label('Kategori')
            ->multiple()
            ->searchable()
            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('Semua kategori');
    }

    /** Produk: dicari di server (katalog bisa ratusan produk). */
    protected function productField(): Select
    {
        return Select::make('product_ids')
            ->label('Produk')
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Product::query()
                ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
            ->getOptionLabelsUsing(fn (array $values): array => Product::query()->withTrashed()->whereIn('id', $values)->pluck('name', 'id')->all())
            ->placeholder('Semua produk');
    }

    /** Shift pada periode terpilih (outlet aktif), label "Budi · 10 Okt 08.00". */
    protected function shiftField(?Closure $visible = null): Select
    {
        return Select::make('shift_ids')
            ->label('Shift')
            ->multiple()
            ->searchable()
            ->options(function (): array {
                [$start, $end] = $this->reportContext()->range();
                $timezone = CurrentOutlet::timezone();

                return CurrentOutlet::scope(Shift::query())
                    ->with('openedBy:id,name')
                    ->whereBetween('opened_at', [$start, $end])
                    ->orderByDesc('opened_at')
                    ->limit(200)
                    ->get(['id', 'opened_by', 'opened_at'])
                    ->mapWithKeys(fn (Shift $shift): array => [
                        $shift->id => ($shift->openedBy->name ?? '–').' · '.$shift->opened_at->setTimezone($timezone)->locale('id')->translatedFormat('j M H.i'),
                    ])
                    ->all();
            })
            ->visible($visible ?? true)
            ->placeholder('Semua shift');
    }

    /** Ganti outlet = ganti pilihan sidebar (outlet di luar akses user → 404 di CurrentOutlet::choose). */
    protected function switchReportOutlet(?string $outletId): void
    {
        $user = $this->reportUser();

        if ($user === null) {
            return;
        }

        CurrentOutlet::choose($user, $outletId === null || $outletId === 'all' ? null : $outletId);

        // Label pemilih outlet di sidebar ikut berubah tanpa reload
        $this->dispatch('pos-outlet-changed', label: CurrentOutlet::selectedId() !== null ? CurrentOutlet::getOrFail()->name : 'Semua outlet');
    }
}
