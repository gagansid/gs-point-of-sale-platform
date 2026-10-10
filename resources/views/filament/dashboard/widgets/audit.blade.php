{{-- Panel audit: 3 kartu temuan; empty state positif bila tidak ada temuan (empty-state.md) --}}
@php($audit = $this->getAudit())
@php($money = fn (?string $v): string => \App\Support\Money::format($v ?? '0.00'))
@php($time = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone(\App\Support\CurrentOutlet::timezone())->locale('id')->translatedFormat('j M, H.i') : '–')
@php($issues = $audit['void_count'] + $audit['discount_count'] + $audit['shift_issue_count'])

<x-filament-widgets::widget>
    <x-filament::section
        heading="Audit & kontrol"
        :description="$issues > 0 ? $issues . ' temuan perlu ditinjau pada periode ini' : 'Tidak ada temuan pada periode ini'"
        icon="heroicon-o-shield-check"
        :icon-color="$issues > 0 ? 'warning' : 'success'"
    >
        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Void --}}
            <div class="pos-dash-audit">
                <div class="pos-dash-audit__head">
                    <span class="pos-dash-audit__title">
                        <x-filament::icon icon="heroicon-o-no-symbol" class="h-5 w-5" /> Void
                    </span>
                    <x-filament::badge :color="$audit['void_count'] > 0 ? 'warning' : 'success'">
                        {{ $audit['void_count'] }} · {{ $money($audit['void_total']) }}
                    </x-filament::badge>
                </div>
                @forelse ($audit['voids'] as $row)
                    <a href="{{ \App\Filament\Dashboard\Resources\Orders\OrderResource::getUrl('view', ['record' => $row['order_id']]) }}" class="pos-dash-audit__item" wire:key="void-{{ $row['order_id'] }}">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['order_number'] }}</span>
                            <span class="tabular-nums font-medium text-gray-950 dark:text-white">{{ $money($row['grand_total']) }}</span>
                        </div>
                        <p class="pos-dash-audit__meta">{{ $time($row['voided_at']) }} · oleh {{ $row['voided_by'] ?? '–' }}@if ($row['approved_by']) · disetujui {{ $row['approved_by'] }}@endif</p>
                        @if ($row['reason'])
                            <p class="pos-dash-audit__meta italic">“{{ $row['reason'] }}”</p>
                        @endif
                    </a>
                @empty
                    <p class="pos-dash-audit__ok"><x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" /> Tidak ada void</p>
                @endforelse
            </div>

            {{-- Diskon dengan approval --}}
            <div class="pos-dash-audit">
                <div class="pos-dash-audit__head">
                    <span class="pos-dash-audit__title">
                        <x-filament::icon icon="heroicon-o-tag" class="h-5 w-5" /> Diskon dengan approval
                    </span>
                    <x-filament::badge :color="$audit['discount_count'] > 0 ? 'info' : 'success'">
                        {{ $audit['discount_count'] }} · {{ $money($audit['discount_total']) }}
                    </x-filament::badge>
                </div>
                @forelse ($audit['discounts'] as $row)
                    <a href="{{ \App\Filament\Dashboard\Resources\Orders\OrderResource::getUrl('view', ['record' => $row['order_id']]) }}" class="pos-dash-audit__item" wire:key="disc-{{ $row['order_id'] }}">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['order_number'] }}</span>
                            <span class="tabular-nums font-medium text-danger-600 dark:text-danger-400">−{{ $money($row['discount_total']) }}</span>
                        </div>
                        <p class="pos-dash-audit__meta">{{ $time($row['completed_at']) }} · kasir {{ $row['cashier'] ?? '–' }} · disetujui {{ $row['approved_by'] ?? '–' }}</p>
                    </a>
                @empty
                    <p class="pos-dash-audit__ok"><x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" /> Tidak ada diskon di atas batas</p>
                @endforelse
            </div>

            {{-- Shift bermasalah --}}
            <div class="pos-dash-audit">
                <div class="pos-dash-audit__head">
                    <span class="pos-dash-audit__title">
                        <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" /> Selisih kas shift
                    </span>
                    <x-filament::badge :color="$audit['shift_issue_count'] > 0 ? 'danger' : 'success'">
                        {{ $audit['shift_issue_count'] }} · {{ $money($audit['shift_difference_total']) }}
                    </x-filament::badge>
                </div>
                @forelse ($audit['shifts'] as $row)
                    @php($diff = (float) $row['difference'])
                    <a href="{{ \App\Filament\Dashboard\Resources\Shifts\ShiftResource::getUrl('view', ['record' => $row['shift_id']]) }}" class="pos-dash-audit__item" wire:key="shift-{{ $row['shift_id'] }}">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['cashier'] ?? '–' }}</span>
                            <span class="tabular-nums font-medium {{ $diff < 0 ? 'text-danger-600 dark:text-danger-400' : ($diff > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-500') }}">
                                {{ $diff < 0 ? 'Kurang' : ($diff > 0 ? 'Lebih' : 'Pas') }} {{ $money(ltrim($row['difference'], '-')) }}
                            </span>
                        </div>
                        <p class="pos-dash-audit__meta">
                            {{ $time($row['closed_at']) }}@if ($row['outlet']) · {{ $row['outlet'] }}@endif · seharusnya {{ $money($row['expected_cash']) }}, aktual {{ $money($row['actual_cash']) }}
                        </p>
                        @if ($row['status'] === 'force_closed')
                            <p class="pos-dash-audit__meta"><x-filament::badge color="danger" size="sm">Ditutup paksa oleh {{ $row['closed_by'] ?? '–' }}</x-filament::badge></p>
                        @endif
                    </a>
                @empty
                    <p class="pos-dash-audit__ok"><x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" /> Semua kas shift sesuai</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
