{{-- PDF rekap shift (X/Z-report), ADR 0012. Data dari ShiftRecap; semua teks di-escape. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
@php($time = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($timezone)->locale('id')->translatedFormat('j M Y H.i') : '–')
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $recap['type'] === 'z_report' ? 'Z-report' : 'X-report' }} shift</title>
    @include('reports.pdf.partials.styles')
</head>
<body>
    <header class="kop">
        <div class="meta">{{ $tenant }}<br>{{ $recap['shift']['outlet'] }}</div>
        <div class="brand"><span>gs</span>.POS</div>
    </header>
    <footer>
        Dicetak oleh {{ $printedBy }} · {{ $printedAt }}
    </footer>

    <h1>{{ $recap['type'] === 'z_report' ? 'Z-report (shift ditutup)' : 'X-report (shift berjalan)' }}</h1>
    <table class="info">
        <tr><td class="label">Kasir</td><td>{{ $recap['shift']['cashier'] }}</td></tr>
        <tr><td class="label">Buka</td><td>{{ $time($recap['shift']['opened_at']) }}</td></tr>
        <tr><td class="label">Tutup</td><td>{{ $time($recap['shift']['closed_at']) }}@if ($recap['shift']['closed_by']) · oleh {{ $recap['shift']['closed_by'] }}@endif</td></tr>
        @if ($recap['shift']['note'])
            <tr><td class="label">Catatan</td><td>{{ $recap['shift']['note'] }}</td></tr>
        @endif
    </table>

    <table class="stats">
        <tr>
            <td><div class="label">Omzet</div><div class="value">{{ $money($recap['sales']['revenue']) }}</div></td>
            <td><div class="label">Transaksi</div><div class="value">{{ number_format($recap['sales']['order_count'], 0, ',', '.') }}</div></td>
            <td><div class="label">Item terjual</div><div class="value">{{ number_format($recap['sales']['items_sold'], 0, ',', '.') }}</div></td>
            <td><div class="label">Void</div><div class="value">{{ $recap['sales']['void_count'] }}</div></td>
        </tr>
    </table>

    <h2>Kas</h2>
    <table class="data">
        <tbody>
            <tr><td>Kas awal</td><td class="num">{{ $money($recap['cash']['opening_cash']) }}</td></tr>
            <tr><td>Penjualan tunai</td><td class="num">{{ $money($recap['cash']['cash_sales']) }}</td></tr>
            <tr><td>Kas seharusnya</td><td class="num">{{ $money($recap['cash']['expected_cash']) }}</td></tr>
            <tr><td>Kas aktual</td><td class="num">{{ $money($recap['cash']['actual_cash']) }}</td></tr>
            <tr>
                <td>Selisih</td>
                <td class="num {{ $recap['cash']['difference'] === null ? '' : (\App\Support\Money::compare($recap['cash']['difference'], '0') === 0 ? 'ok' : 'minus') }}">{{ $money($recap['cash']['difference']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Penjualan</h2>
    <table class="data">
        <tbody>
            <tr><td>Diskon</td><td class="num">{{ $money($recap['sales']['discount_total']) }}</td></tr>
            <tr><td>Service charge</td><td class="num">{{ $money($recap['sales']['service_total']) }}</td></tr>
            <tr><td>Pajak</td><td class="num">{{ $money($recap['sales']['tax_total']) }}</td></tr>
            <tr><td>Pembulatan</td><td class="num">{{ $money($recap['sales']['rounding_total']) }}</td></tr>
            <tr><td>Nilai void</td><td class="num">{{ $money($recap['sales']['void_total']) }}</td></tr>
            <tr><td>Open bill belum lunas</td><td class="num">{{ $recap['sales']['open_bill_count'] }}</td></tr>
        </tbody>
    </table>

    <h2>Metode bayar</h2>
    @if ($recap['payment_methods'] === [])
        <div class="empty">Belum ada pembayaran</div>
    @else
        <table class="data">
            <thead><tr><th>Metode</th><th class="num">Transaksi</th><th class="num">Nominal</th></tr></thead>
            <tbody>
                @foreach ($recap['payment_methods'] as $method)
                    <tr><td>{{ $method['name'] }}</td><td class="num">{{ $method['count'] }}</td><td class="num">{{ $money($method['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Produk terjual</h2>
    @if ($recap['products'] === [])
        <div class="empty">Belum ada produk terjual</div>
    @else
        <table class="data">
            <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Omzet</th></tr></thead>
            <tbody>
                @foreach ($recap['products'] as $product)
                    <tr><td>{{ $product['product_name'] }}</td><td class="num">{{ $product['qty'] }}</td><td class="num">{{ $money($product['revenue']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
