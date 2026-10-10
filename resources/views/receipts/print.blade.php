{{--
    Struk cetak ulang (desain sementara, 80mm). Data = ReceiptResource (sama dengan aplikasi kasir).
    Semua teks dari input pengguna di-escape ({{ }}).
--}}
@php
    use App\Support\Money;

    $rp = fn (string $value): string => Money::format($value);
    $rate = fn (string $value): string => rtrim(rtrim($value, '0'), '.');
    $t = $receipt['totals'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Struk {{ $receipt['order_number'] }}</title>
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f4; color: #111; font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .paper { width: 80mm; margin: 24px auto; padding: 6mm 5mm; background: #fff; box-shadow: 0 2px 10px rgb(0 0 0 / .08); }
        .center { text-align: center; }
        .muted { color: #555; }
        .bold { font-weight: 700; }
        .big { font-size: 15px; }
        .rule { margin: 8px 0; border-top: 1px dashed #999; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .row > :last-child { text-align: right; white-space: nowrap; }
        .line { margin-bottom: 6px; }
        .sub { padding-left: 8px; color: #555; font-size: 11px; }
        .void { margin: 6px 0; padding: 4px; border: 2px solid #111; font-size: 16px; font-weight: 700; letter-spacing: 4px; text-align: center; }
        .actions { width: 80mm; margin: 0 auto 24px; display: flex; gap: 8px; }
        .actions button { flex: 1; padding: 8px; border: 1px solid #2d4282; border-radius: 6px; background: #2d4282; color: #fff; font: 600 13px system-ui, sans-serif; cursor: pointer; }
        .actions button.secondary { background: #fff; color: #2d4282; }
        @media print {
            body { background: #fff; }
            .paper { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="paper">
        <div class="center">
            <div class="bold big">{{ $receipt['outlet']['name'] }}</div>
            @if (filled($receipt['outlet']['address']))
                <div class="muted">{{ $receipt['outlet']['address'] }}</div>
            @endif
            @if (filled($receipt['outlet']['header']))
                <div>{{ $receipt['outlet']['header'] }}</div>
            @endif
        </div>

        @if ($receipt['is_void'])
            <div class="void">BATAL</div>
        @endif

        <div class="rule"></div>
        <div class="row"><span>{{ $receipt['order_number'] }}</span><span>{{ $receipt['created_at_local'] }}</span></div>
        <div class="row"><span>Kasir: {{ $receipt['cashier_name'] }}</span><span>{{ $receipt['order_type_label'] }}{{ filled($receipt['table_label']) ? ' · '.$receipt['table_label'] : '' }}</span></div>
        <div class="rule"></div>

        @foreach ($receipt['lines'] as $line)
            <div class="line">
                <div>{{ $line['name'] }}</div>
                @if ($line['options'] !== [])
                    <div class="sub">{{ implode(', ', $line['options']) }}</div>
                @endif
                @if (filled($line['notes']))
                    <div class="sub">"{{ $line['notes'] }}"</div>
                @endif
                <div class="row">
                    <span class="muted">{{ $line['qty'] }} x {{ $rp(Money::add($line['unit_price'], $line['options_total'])) }}</span>
                    <span>{{ $rp($line['line_total']) }}</span>
                </div>
                @if (Money::isPositive($line['discount']))
                    <div class="row sub"><span>Diskon</span><span>-{{ $rp($line['discount']) }}</span></div>
                @endif
            </div>
        @endforeach

        <div class="rule"></div>
        <div class="row"><span>Subtotal</span><span>{{ $rp($t['subtotal']) }}</span></div>
        @if (Money::isPositive($t['discount_total']))
            <div class="row"><span>Diskon</span><span>-{{ $rp($t['discount_total']) }}</span></div>
        @endif
        @if (Money::isPositive($t['service_total']))
            <div class="row"><span>Service {{ $rate($t['service_rate']) }}%</span><span>{{ $rp($t['service_total']) }}</span></div>
        @endif
        @if (Money::isPositive($t['tax_total']))
            <div class="row"><span>Pajak {{ $rate($t['tax_rate']) }}%{{ $t['tax_inclusive'] ? ' (termasuk)' : '' }}</span><span>{{ $rp($t['tax_total']) }}</span></div>
        @endif
        {{-- Q48: bawaan pembulatan digabung ke total (tanpa baris) --}}
        @if ($receipt['outlet']['show_rounding'] && Money::compare($t['rounding'], '0') !== 0)
            <div class="row"><span>Pembulatan</span><span>{{ $rp($t['rounding']) }}</span></div>
        @endif
        <div class="row bold big"><span>TOTAL</span><span>{{ $rp($t['grand_total']) }}</span></div>

        <div class="rule"></div>
        @foreach ($receipt['payments'] as $payment)
            <div class="row"><span>{{ $payment['method'] }}{{ filled($payment['reference']) ? ' ('.$payment['reference'].')' : '' }}</span><span>{{ $rp($payment['tendered']) }}</span></div>
        @endforeach
        <div class="row"><span>Kembalian</span><span>{{ $rp($t['change_total']) }}</span></div>

        @if ($receipt['is_void'] && filled($receipt['void_reason']))
            <div class="rule"></div>
            <div>Alasan batal: {{ $receipt['void_reason'] }}</div>
        @endif

        <div class="rule"></div>
        <div class="center">{{ $receipt['outlet']['footer'] ?: 'Terima kasih' }}</div>
        <div class="center muted">Cetak ulang</div>
    </main>

    <div class="actions">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" class="secondary" onclick="window.close()">Tutup</button>
    </div>

    @if ($autoprint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
