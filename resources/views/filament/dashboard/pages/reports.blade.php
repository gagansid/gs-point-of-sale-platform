{{-- Laporan penjualan (layout.md: filter → stat → tabel; content.md: format Rupiah & tanggal) --}}
@php($report = $this->getReport())
@php($s = $report['summary'])
@php($money = fn (string $v): string => \App\Support\Money::format($v))

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->getFiltersForm() }}
    </x-filament::section>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Omzet', $money($s['revenue']), 'heroicon-o-banknotes'],
            ['Transaksi', number_format($s['order_count'], 0, ',', '.'), 'heroicon-o-receipt-percent'],
            ['Rata-rata transaksi', $money($s['average']), 'heroicon-o-calculator'],
            ['Item terjual', number_format($s['items_sold'], 0, ',', '.'), 'heroicon-o-cube'],
        ] as [$label, $value, $icon])
            <x-filament::section>
                <div class="flex items-center justify-between text-sm font-medium text-gray-500 dark:text-gray-400">
                    <span>{{ $label }}</span>
                    <x-filament::icon :icon="$icon" class="h-5 w-5" />
                </div>
                <div class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Rincian" class="lg:col-span-1">
            <dl class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ([
                    ['Total diskon', $money($s['discount_total'])],
                    ['Service charge', $money($s['service_total'])],
                    ['Pajak', $money($s['tax_total'])],
                    ['Pembulatan', $money($s['rounding_total'])],
                    ['Transaksi void', number_format($s['void_count'], 0, ',', '.').' ('.$money($s['void_total']).')'],
                ] as [$label, $value])
                    <div class="flex justify-between py-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section heading="Per metode bayar" class="lg:col-span-2">
            @include('filament.dashboard.pages.partials.report-table', [
                'columns' => ['Metode', 'Transaksi', 'Nominal'],
                'rows' => array_map(fn ($m) => [$m['name'], number_format($m['count'], 0, ',', '.'), $money($m['amount'])], $report['payment_methods']),
            ])
        </x-filament::section>
    </div>

    <x-filament::section heading="Per produk" description="Urut omzet terbesar">
        @include('filament.dashboard.pages.partials.report-table', [
            'columns' => ['Produk', 'Qty', 'Omzet'],
            'rows' => array_map(fn ($p) => [$p['product_name'], number_format($p['qty'], 0, ',', '.'), $money($p['revenue'])], $report['products']),
        ])
    </x-filament::section>
</x-filament-panels::page>
