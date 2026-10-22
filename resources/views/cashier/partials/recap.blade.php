{{-- Rekap shift (X/Z-report) dari ShiftRecap::for(); semua teks di-escape. Gaya Q131: statistik di atas, bagian sebagai sub-kartu. --}}
@php
    $money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
    $time = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($timezone)->format('d-m-Y, H:i') : '–';
    $diff = $recap['cash']['difference'];
    $clock = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($timezone)->format('H:i') : 'sekarang';
    $count = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<section class="pos-card pos-recap">
    <div class="pos-card-head">
        <div>
            <h2>{{ $recap['type'] === 'z_report' ? 'Z-report (shift ditutup)' : 'X-report (shift berjalan)' }}</h2>
            <p class="pos-card-sub">
                {{ $recap['shift']['cashier'] }} · dibuka {{ $time($recap['shift']['opened_at']) }}
                @if ($recap['shift']['closed_at']) · ditutup {{ $time($recap['shift']['closed_at']) }} oleh {{ $recap['shift']['closed_by'] }}@endif
            </p>
        </div>
        <a href="{{ route('cashier.shifts.pdf', $recap['shift']['id']) }}" class="site-btn site-btn-outline">@svg('heroicon-o-arrow-down-tray', ['aria-hidden' => 'true']) Unduh PDF</a>
    </div>

    <div class="pos-card-body pos-recap-body">
        <div class="pos-mini-stats">
            <div><small>Omzet</small><strong>{{ $money($recap['sales']['revenue']) }}</strong></div>
            <div><small>Transaksi</small><strong>{{ $count($recap['sales']['order_count']) }}</strong></div>
            <div class="is-primary"><small>Kas seharusnya</small><strong>{{ $money($recap['cash']['expected_cash']) }}</strong></div>
        </div>

        <div class="pos-section">
            <h3>Penjualan</h3>
            <dl class="pos-dl">
                <div><dt>Item terjual</dt><dd>{{ $count($recap['sales']['items_sold']) }}</dd></div>
                <div><dt>Diskon</dt><dd>{{ $money($recap['sales']['discount_total']) }}</dd></div>
                <div><dt>Void</dt><dd>{{ $recap['sales']['void_count'] }} · {{ $money($recap['sales']['void_total']) }}</dd></div>
                @if ($recap['sales']['open_bill_count'] > 0)
                    <div class="is-warning"><dt>Open bill belum lunas</dt><dd>{{ $recap['sales']['open_bill_count'] }}</dd></div>
                @endif
            </dl>
        </div>

        <div class="pos-section">
            <h3>Kas</h3>
            <dl class="pos-dl">
                <div>
                    <dt>Kas awal
                        @if ($recap['shift']['opening_cash_source'] === 'carry_over')
                            <small>Sisa laci shift sebelumnya</small>
                        @elseif ($recap['shift']['opening_cash_source'] === 'adjusted')
                            <small>Dikoreksi dari {{ $money($recap['cash']['carried_cash']) }} oleh {{ $recap['shift']['opening_cash_adjusted_by'] ?? '–' }}@if ($recap['shift']['opening_cash_approved_by']), disetujui {{ $recap['shift']['opening_cash_approved_by'] }}@endif: “{{ $recap['shift']['opening_cash_note'] }}”</small>
                        @endif
                    </dt>
                    <dd>{{ $money($recap['cash']['opening_cash']) }}</dd>
                </div>
                <div><dt>Penjualan tunai</dt><dd>{{ $money($recap['cash']['cash_sales']) }}</dd></div>
                @if (\App\Support\Money::isPositive($recap['cash']['cash_in']))
                    <div><dt>Kas masuk</dt><dd>+{{ $money($recap['cash']['cash_in']) }}</dd></div>
                @endif
                @if (\App\Support\Money::isPositive($recap['cash']['cash_out']))
                    <div><dt>Kas keluar</dt><dd>−{{ $money($recap['cash']['cash_out']) }}</dd></div>
                @endif
                <div class="is-total"><dt>Kas seharusnya</dt><dd>{{ $money($recap['cash']['expected_cash']) }}</dd></div>
                @if ($recap['cash']['actual_cash'] !== null)
                    <div><dt>Kas dihitung</dt><dd>{{ $money($recap['cash']['actual_cash']) }}</dd></div>
                    <div @class(['is-danger' => $diff !== null && \App\Support\Money::compare($diff, '0') < 0, 'is-success' => $diff !== null && \App\Support\Money::compare($diff, '0') === 0])>
                        <dt>Selisih</dt><dd>{{ $money($diff) }}</dd>
                    </div>
                    @if ($recap['cash']['cash_deposited'] !== null)
                        <div><dt>Disetor</dt><dd>{{ $money($recap['cash']['cash_deposited']) }}</dd></div>
                        <div><dt>Ditinggal di laci</dt><dd>{{ $money($recap['cash']['closing_float']) }}</dd></div>
                    @endif
                @endif
                @if ($recap['shift']['note'])
                    <div><dt>Catatan</dt><dd class="is-text">{{ $recap['shift']['note'] }}</dd></div>
                @endif
            </dl>
        </div>

        @if ($recap['cash_movements'] !== [])
            <div class="pos-section is-table">
                <h3>Kas masuk/keluar</h3>
                <table class="pos-table">
                    <thead><tr><th>Jam</th><th>Alasan</th><th class="num">Nominal</th></tr></thead>
                    <tbody>
                        @foreach ($recap['cash_movements'] as $row)
                            <tr>
                                <td class="num-tabular">{{ $clock($row['created_at']) }}</td>
                                <td class="pos-receipt-name">{{ $row['reason_label'] }}@if ($row['note']) · {{ $row['note'] }}@endif<small>{{ $row['user'] }}@if ($row['approved_by']) · disetujui {{ $row['approved_by'] }}@endif</small></td>
                                <td @class(['num', 'is-strong', 'is-danger' => $row['type'] === 'out'])>{{ $row['type'] === 'out' ? '−' : '+' }}{{ $money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($recap['operators'] !== [])
            <div class="pos-section is-table">
                <h3>Kasir bertugas</h3>
                <table class="pos-table">
                    <thead><tr><th>Kasir</th><th class="num">Transaksi</th><th class="num">Tunai</th></tr></thead>
                    <tbody>
                        @foreach ($recap['operators'] as $row)
                            <tr>
                                <td class="pos-receipt-name">{{ $row['name'] }}@if ($row['periods'] !== [])<small>{{ collect($row['periods'])->map(fn ($p) => $clock($p['started_at']).'–'.$clock($p['ended_at']))->implode(', ') }}</small>@endif</td>
                                <td class="num">{{ $row['order_count'] }}</td>
                                <td class="num is-strong">{{ $money($row['cash_sales']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($recap['payment_methods'] !== [])
            <div class="pos-section is-table">
                <h3>Metode bayar</h3>
                <table class="pos-table">
                    <thead><tr><th>Metode</th><th class="num">Transaksi</th><th class="num">Nominal</th></tr></thead>
                    <tbody>
                        @foreach ($recap['payment_methods'] as $row)
                            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num is-strong">{{ $money($row['amount']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($recap['products'] !== [])
            <div class="pos-section is-table">
                <h3>Produk</h3>
                <table class="pos-table">
                    <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Omzet</th></tr></thead>
                    <tbody>
                        @foreach ($recap['products'] as $row)
                            <tr><td class="pos-receipt-name">{{ $row['product_name'] }}</td><td class="num">{{ $row['qty'] }}</td><td class="num is-strong">{{ $money($row['revenue']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
