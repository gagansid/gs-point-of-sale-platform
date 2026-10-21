{{-- Rincian finansial (laporan): urutan dari bruto ke omzet diterima, lalu penjualan bersih & void --}}
@php($s = $this->getSummary())
@php($money = fn (string $v): string => \App\Support\Money::formatWhole($v))

<x-filament-widgets::widget>
    <x-filament::section heading="Rincian finansial" description="Dari penjualan bruto ke omzet yang diterima" icon="heroicon-o-banknotes">
        <div class="grid gap-x-10 gap-y-2 lg:grid-cols-2">
            <dl class="pos-fin-list">
                <div><dt>Penjualan bruto</dt><dd>{{ $money($s['subtotal_total']) }}</dd></div>
                <div><dt>Diskon</dt><dd class="pos-fin-list__minus">−{{ $money($s['discount_total']) }}</dd></div>
                <div><dt>Service charge</dt><dd>{{ $money($s['service_total']) }}</dd></div>
                <div><dt>Pajak</dt><dd>{{ $money($s['tax_total']) }}</dd></div>
                <div><dt>Pembulatan</dt><dd>{{ $money($s['rounding_total']) }}</dd></div>
                <div class="pos-fin-list__total"><dt>Omzet diterima</dt><dd>{{ $money($s['revenue']) }}</dd></div>
            </dl>
            <dl class="pos-fin-list">
                <div class="pos-fin-list__total"><dt>Penjualan bersih <span class="pos-fin-list__hint">tanpa pajak, service & pembulatan</span></dt><dd>{{ $money($s['net_sales']) }}</dd></div>
                <div><dt>Transaksi selesai</dt><dd>{{ number_format($s['order_count'], 0, ',', '.') }}</dd></div>
                <div><dt>Item terjual</dt><dd>{{ number_format($s['items_sold'], 0, ',', '.') }}</dd></div>
                <div><dt>Rata-rata transaksi</dt><dd>{{ $money($s['average']) }}</dd></div>
                <div>
                    <dt>Void</dt>
                    <dd class="{{ $s['void_count'] > 0 ? 'pos-fin-list__warn' : '' }}">{{ $s['void_count'] }} · {{ $money($s['void_total']) }}</dd>
                </div>
                {{-- Retur sebagian menurut tanggal retur (ADR 0018) --}}
                <div>
                    <dt>Retur</dt>
                    <dd class="{{ $s['refund_count'] > 0 ? 'pos-fin-list__warn' : '' }}">{{ $s['refund_count'] }} · {{ $money($s['refund_total']) }}</dd>
                </div>
                <div class="pos-fin-list__total"><dt>Omzet setelah retur</dt><dd>{{ $money($s['net_revenue']) }}</dd></div>
            </dl>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
