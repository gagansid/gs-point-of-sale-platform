{{-- Rekap shift (X/Z-report) dari ShiftRecap::for(); semua teks di-escape. --}}
@php
    $money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
    $time = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($timezone)->locale('id')->translatedFormat('j M Y H.i') : '–';
    $diff = $recap['cash']['difference'];
@endphp
<section class="cashier-card">
    <div class="cashier-card-head">
        <h2>{{ $recap['type'] === 'z_report' ? 'Z-report (shift ditutup)' : 'X-report (shift berjalan)' }}</h2>
        <a href="{{ route('cashier.shifts.pdf', $recap['shift']['id']) }}" class="site-btn site-btn-outline">PDF</a>
    </div>
    <dl class="cashier-dl">
        <dt>Kasir</dt><dd>{{ $recap['shift']['cashier'] }}</dd>
        <dt>Dibuka</dt><dd>{{ $time($recap['shift']['opened_at']) }}</dd>
        @if ($recap['shift']['closed_at'])
            <dt>Ditutup</dt><dd>{{ $time($recap['shift']['closed_at']) }} · {{ $recap['shift']['closed_by'] }}</dd>
        @endif
        <dt>Transaksi</dt><dd>{{ number_format($recap['sales']['order_count'], 0, ',', '.') }}</dd>
        <dt>Omzet</dt><dd>{{ $money($recap['sales']['revenue']) }}</dd>
        <dt>Item terjual</dt><dd>{{ number_format($recap['sales']['items_sold'], 0, ',', '.') }}</dd>
        <dt>Diskon</dt><dd>{{ $money($recap['sales']['discount_total']) }}</dd>
        <dt>Void</dt><dd>{{ $recap['sales']['void_count'] }} · {{ $money($recap['sales']['void_total']) }}</dd>
        @if ($recap['sales']['open_bill_count'] > 0)
            <dt>Open bill belum lunas</dt><dd>{{ $recap['sales']['open_bill_count'] }}</dd>
        @endif
    </dl>

    <h3>Kas</h3>
    <dl class="cashier-dl">
        <dt>Kas awal</dt><dd>{{ $money($recap['cash']['opening_cash']) }}</dd>
        <dt>Penjualan tunai</dt><dd>{{ $money($recap['cash']['cash_sales']) }}</dd>
        <dt>Kas seharusnya</dt><dd><strong>{{ $money($recap['cash']['expected_cash']) }}</strong></dd>
        @if ($recap['cash']['actual_cash'] !== null)
            <dt>Kas aktual</dt><dd>{{ $money($recap['cash']['actual_cash']) }}</dd>
            <dt>Selisih</dt>
            <dd @class(['cashier-negative' => $diff !== null && \App\Support\Money::compare($diff, '0') < 0])>{{ $money($diff) }}</dd>
        @endif
        @if ($recap['shift']['note'])
            <dt>Catatan</dt><dd>{{ $recap['shift']['note'] }}</dd>
        @endif
    </dl>

    @if ($recap['payment_methods'] !== [])
        <h3>Metode bayar</h3>
        <table class="cashier-table">
            <thead><tr><th>Metode</th><th class="num">Transaksi</th><th class="num">Nominal</th></tr></thead>
            <tbody>
                @foreach ($recap['payment_methods'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($recap['products'] !== [])
        <h3>Produk</h3>
        <table class="cashier-table">
            <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Omzet</th></tr></thead>
            <tbody>
                @foreach ($recap['products'] as $row)
                    <tr><td>{{ $row['product_name'] }}</td><td class="num">{{ $row['qty'] }}</td><td class="num">{{ $money($row['revenue']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
