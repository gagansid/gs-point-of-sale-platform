{{-- /cashier/orders: transaksi shift terbuka di browser ini. Gaya halaman kasir Q131. --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
@php($filters = ['all' => 'Semua', 'completed' => 'Selesai', 'unpaid' => 'Menunggu bayar', 'voided' => 'Void'])
<x-cashier.layout title="Riwayat" active="orders">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <h1>Riwayat shift ini</h1>
                <p>Transaksi di shift yang sedang terbuka. Klik baris untuk melihat detail.</p>
            </div>
            <a href="{{ route('cashier.refunds.search') }}" class="site-btn site-btn-outline">@svg('heroicon-o-arrow-uturn-left', ['aria-hidden' => 'true']) Retur transaksi</a>
        </header>

        @if ($shift === null)
            <section class="pos-card">
                <div class="pos-empty">
                    <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-calculator')</span>
                    <strong>Belum ada shift terbuka</strong>
                    <p>Buka shift dulu untuk mulai mencatat transaksi.</p>
                    <a href="{{ route('cashier.shift') }}" class="site-btn site-btn-primary">Buka shift</a>
                </div>
            </section>
        @else
            <div class="pos-stats">
                <div class="pos-stat">
                    <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-receipt-percent')</span>
                    <div><small>Transaksi</small><strong>{{ number_format($stats['count'], 0, ',', '.') }}</strong></div>
                </div>
                <div class="pos-stat is-success">
                    <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-banknotes')</span>
                    <div><small>Omzet</small><strong>{{ $money($stats['revenue']) }}</strong></div>
                </div>
                <div class="pos-stat">
                    <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-chart-bar')</span>
                    <div><small>Rata-rata</small><strong>{{ $money($stats['average']) }}</strong></div>
                </div>
                <div @class(['pos-stat', 'is-danger' => $stats['voided'] > 0])>
                    <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-x-circle')</span>
                    <div><small>Void</small><strong>{{ number_format($stats['voided'], 0, ',', '.') }}</strong></div>
                </div>
            </div>

            <section class="pos-card">
                <div class="pos-card-head">
                    <nav class="pos-tabs" aria-label="Filter status">
                        @foreach ($filters as $key => $label)
                            <a href="{{ route('cashier.orders', array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
                                @class(['is-active' => $filter === $key]) @if ($filter === $key) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                    <form method="GET" action="{{ route('cashier.orders') }}" class="pos-search-form" role="search" novalidate>
                        @if ($filter !== 'all')<input type="hidden" name="status" value="{{ $filter }}">@endif
                        <label class="pos-search-field">
                            @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
                            <input name="q" type="search" maxlength="30" value="{{ $search }}" placeholder="Cari nomor transaksi atau meja" aria-label="Cari transaksi">
                        </label>
                        <button type="submit" class="site-btn site-btn-outline">Cari</button>
                    </form>
                </div>

                @if ($orders->isEmpty())
                    @php($filtered = $search !== '' || $filter !== 'all')
                    <div class="pos-empty">
                        <span class="pos-empty-icon" aria-hidden="true">@svg($filtered ? 'heroicon-o-magnifying-glass' : 'heroicon-o-receipt-percent')</span>
                        <strong>{{ $filtered ? 'Transaksi tidak ditemukan' : 'Belum ada transaksi di shift ini' }}</strong>
                        <p>{{ $filtered ? 'Coba kata kunci atau status lain.' : 'Transaksi yang dibayar di layar Transaksi akan muncul di sini.' }}</p>
                        @if ($filtered)
                            <a href="{{ route('cashier.orders') }}" class="site-btn site-btn-outline">Tampilkan semua</a>
                        @else
                            <a href="{{ route('cashier.home') }}" class="site-btn site-btn-primary">Mulai transaksi</a>
                        @endif
                    </div>
                @else
                    <div class="pos-table-wrap">
                        <table class="pos-table is-clickable">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Meja</th>
                                    <th>Waktu</th>
                                    <th class="is-secondary">Kasir</th>
                                    <th class="is-secondary">Metode</th>
                                    <th>Status</th>
                                    <th class="num">Total</th>
                                    <th class="num"><span class="sr-only">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    {{-- Seluruh baris menuju detail (data-href); tautan nomor tetap ada untuk keyboard & pembaca layar --}}
                                    <tr data-href="{{ route('cashier.orders.show', $order->id) }}">
                                        <td class="is-code"><a href="{{ route('cashier.orders.show', $order->id) }}">{{ $order->order_number }}</a></td>
                                        <td>{{ $order->table_label ?: '—' }}</td>
                                        <td class="num-tabular">{{ $order->created_at->setTimezone($timezone)->format('H:i') }}</td>
                                        <td class="is-secondary">{{ $order->user?->name }}</td>
                                        <td class="is-secondary">{{ $order->payments->map(fn ($p) => $p->method?->name)->filter()->unique()->implode(', ') ?: '—' }}</td>
                                        <td><span @class(['pos-badge', 'is-'.$order->status->value])>{{ $order->status->getLabel() }}</span></td>
                                        <td class="num is-strong">{{ $money($order->grand_total) }}</td>
                                        <td class="num">
                                            <span class="pos-row-actions">
                                                <a href="{{ route('cashier.orders.show', $order->id) }}" class="pos-icon-btn" title="Detail" aria-label="Detail {{ $order->order_number }}">@svg('heroicon-o-eye', ['aria-hidden' => 'true'])</a>
                                                @can('reprint', $order)
                                                    <a href="{{ route('cashier.orders.receipt', ['order' => $order->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="pos-icon-btn" title="Cetak struk" aria-label="Cetak struk {{ $order->order_number }}">@svg('heroicon-o-printer', ['aria-hidden' => 'true'])</a>
                                                @endcan
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-cashier.layout>
