{{--
    PDF tagihan / kuitansi langganan (Q126, App\Services\Billing\InvoicePdf). dompdf: tata letak tabel, tanpa flex/grid.
    $receipt = lunas (judul KUITANSI, cap LUNAS, terbilang). Warna mengikuti design-tokens.md (sama dengan PDF laporan).
    Semua teks di-escape.
--}}
@php
    /** @var \App\Models\SubscriptionInvoice $invoice */
    $money = fn ($v): string => \App\Support\Money::format((string) $v);
    $date = fn ($d, string $f = 'j F Y'): string => $d ? $d->setTimezone($timezone)->locale('id')->translatedFormat($f) : '–';
    $hasDiscount = bccomp((string) $invoice->discount_amount, '0', 2) > 0;
    $hasTax = bccomp((string) $invoice->tax_amount, '0', 2) > 0;
    $title = $receipt ? 'Kuitansi' : 'Tagihan';
    $icon = public_path('images/brand/icon-192.png');
    $banks = \App\Models\Bank::query()->whereKey(array_filter(array_column($manual, 'bank_id')))->get()->keyBy('id');
    $method = $invoice->payment_method?->getLabel() ?? match ($invoice->paid_via) { 'manual' => 'Transfer manual', 'midtrans' => 'Online (Midtrans)', default => null };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->number }}</title>
    @include('reports.pdf.partials.styles')
    <style>
        @page { margin: 16mm 16mm 20mm 16mm; }
        body { font-size: 9pt; }
        .w-full { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .brand-cell img { width: 11mm; height: 11mm; vertical-align: middle; }
        .brand-name { display: inline-block; vertical-align: middle; margin-left: 2.5mm; font-size: 16pt; font-weight: bold; color: #1B2A55; }
        .brand-name span { color: #8A919E; }
        .issuer { margin-top: 2mm; color: #626D7D; font-size: 8pt; line-height: 1.5; }
        .issuer strong { color: #1E2633; font-size: 9pt; }
        .doc-title { text-align: right; }
        .doc-title .kicker { font-size: 22pt; font-weight: bold; letter-spacing: 3pt; color: #2D4282; text-transform: uppercase; }
        .doc-title .number { margin-top: 1mm; font-family: 'DejaVu Sans Mono', monospace; font-size: 9.5pt; color: #626D7D; }
        .status { display: inline-block; margin-top: 2mm; padding: 1mm 3mm; border-radius: 3mm; font-size: 8pt; font-weight: bold; }
        .status-pending { background: #FFF4E0; color: #B26B00; }
        .status-paid { background: #E3F6E6; color: #1F7A2E; }
        .status-closed { background: #EFF0F3; color: #626D7D; }
        .rule { height: 0; border-top: 2px solid #2D4282; margin: 5mm 0 5mm; }
        .label { color: #7E8896; font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .6pt; margin-bottom: 1.2mm; }
        .party td { vertical-align: top; }
        .party .name { font-size: 11pt; font-weight: bold; color: #1E2633; }
        .party .muted { color: #626D7D; line-height: 1.5; }
        .amount-box { background: #F1F3F9; border-radius: 2mm; padding: 3.5mm 4mm; text-align: right; }
        .amount-box .value { font-size: 18pt; font-weight: bold; color: #1E2633; }
        .amount-box .sub { color: #626D7D; font-size: 8pt; }
        .facts { margin-top: 5mm; border: 1px solid #E6E7EB; border-radius: 2mm; }
        .facts td { padding: 2.5mm 3.5mm; width: 25%; vertical-align: top; }
        .facts .k { color: #7E8896; font-size: 7.5pt; }
        .facts .v { margin-top: .6mm; font-weight: bold; }
        table.lines { margin-top: 6mm; }
        table.lines th { background: #F9FAFB; color: #626D7D; font-size: 7.5pt; text-transform: uppercase; text-align: left; padding: 2mm 2.5mm; border-top: 1px solid #E6E7EB; border-bottom: 1px solid #E6E7EB; }
        table.lines td { padding: 2.5mm; border-bottom: 1px solid #EFF0F3; vertical-align: top; }
        table.lines .num { text-align: right; white-space: nowrap; }
        table.lines .sub { color: #7E8896; font-size: 7.5pt; margin-top: .5mm; }
        .totals { width: 72mm; margin: 3mm 0 0 auto; border-collapse: collapse; }
        .totals td { padding: 1.2mm 2.5mm; color: #626D7D; }
        .totals td.num { text-align: right; }
        .totals tr.total td { border-top: 2px solid #1E2633; padding-top: 2.5mm; color: #1E2633; font-size: 11.5pt; font-weight: bold; }
        .terbilang { margin-top: 4mm; padding: 2.5mm 3.5mm; border-left: 3px solid #2D4282; background: #F9FAFB; font-style: italic; color: #1E2633; }
        .box { margin-top: 6mm; border: 1px solid #E6E7EB; border-radius: 2mm; padding: 3.5mm 4mm; }
        .box h3 { margin: 0 0 2mm; font-size: 9.5pt; color: #1E2633; }
        .box table td { padding: .8mm 0; vertical-align: top; }
        .box .k { width: 38mm; color: #626D7D; }
        .bank-row td { padding: 1.4mm 0; border-bottom: 1px dashed #E6E7EB; }
        .bank-chip { display: inline-block; min-width: 13mm; padding: .8mm 1.5mm; border-radius: 1mm; color: #fff; font-size: 7.5pt; font-weight: bold; text-align: center; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .stamp { position: absolute; top: 4mm; left: 14mm; padding: 2mm 6mm; border: 3px solid #2FB344; border-radius: 2mm; color: #2FB344; font-size: 20pt; font-weight: bold; letter-spacing: 3pt; transform: rotate(-12deg); opacity: .85; }
        .note { margin-top: 5mm; color: #626D7D; line-height: 1.5; }
        footer.doc { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #7E8896; border-top: 1px solid #E6E7EB; padding-top: 2mm; text-align: center; }
    </style>
</head>
<body>
    <footer class="doc">
        {{ $issuer['name'] ?? 'gs.POS' }} · Dokumen ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan basah · {{ $invoice->number }}
    </footer>

    {{-- Kop --}}
    <table class="w-full head">
        <tr>
            <td class="brand-cell">
                @if (is_file($icon))<img src="{{ $icon }}" alt="">@endif<span class="brand-name"><span>gs</span>.POS</span>
                <div class="issuer">
                    <strong>{{ $issuer['name'] ?? 'gs.POS' }}</strong>
                    @if (filled($issuer['address'] ?? null))<br>{{ $issuer['address'] }}@endif
                    @if (filled($issuer['email'] ?? null))<br>{{ $issuer['email'] }}@endif
                </div>
            </td>
            <td class="doc-title">
                <div class="kicker">{{ $title }}</div>
                <div class="number">{{ $invoice->number }}</div>
                <div class="status {{ $receipt ? 'status-paid' : ($invoice->status === \App\Enums\InvoiceStatus::Pending ? 'status-pending' : 'status-closed') }}">{{ $invoice->statusLabel() }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    {{-- Penerima & total --}}
    <table class="w-full party">
        <tr>
            <td style="width: 58%">
                <div class="label">{{ $receipt ? 'Diterima dari' : 'Ditagihkan kepada' }}</div>
                <div class="name">{{ $tenant?->name ?? '–' }}</div>
                @if ($owner)
                    <div class="muted">{{ $owner->name }}<br>{{ $owner->email }}</div>
                @endif
            </td>
            <td>
                <div class="amount-box">
                    <div class="label">{{ $receipt ? 'Jumlah dibayar' : 'Total tagihan' }}</div>
                    <div class="value">{{ $money($invoice->amount) }}</div>
                    <div class="sub">{{ $receipt ? 'Lunas '.$date($invoice->paid_at) : 'Jatuh tempo '.$date($invoice->due_at) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="w-full facts">
        <tr>
            <td><div class="k">Tanggal tagihan</div><div class="v">{{ $date($invoice->created_at) }}</div></td>
            <td><div class="k">{{ $receipt ? 'Tanggal bayar' : 'Jatuh tempo' }}</div><div class="v">{{ $receipt ? $date($invoice->paid_at) : $date($invoice->due_at) }}</div></td>
            <td><div class="k">Jenis</div><div class="v">{{ $invoice->kind->getLabel() }}</div></td>
            <td><div class="k">Periode layanan</div><div class="v">{{ $invoice->period_start ? $date($invoice->period_start, 'j M Y').' – '.$date($invoice->period_end, 'j M Y') : ($invoice->plan_name ? 'Mulai saat lunas' : '–') }}</div></td>
        </tr>
    </table>

    {{-- Rincian --}}
    <table class="w-full lines">
        <thead>
            <tr><th style="width: 7mm">#</th><th>Uraian</th><th class="num" style="width: 16mm">Jumlah</th><th class="num" style="width: 32mm">Harga satuan</th><th class="num" style="width: 32mm">Total</th></tr>
        </thead>
        <tbody>
            @forelse ($invoice->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <strong>{{ $item->name }}</strong>
                        <div class="sub">{{ match ($item->type) { 'plan' => 'Paket'.($invoice->billing_cycle ? ' · '.$invoice->billing_cycle->getLabel() : ''), 'addon' => 'Add-on', default => 'Biaya lain' } }}</div>
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num"><strong>{{ $money($item->amount) }}</strong></td>
                </tr>
            @empty
                <tr><td>1</td><td><strong>{{ $invoice->title ?? $invoice->description() }}</strong></td><td class="num">1</td><td class="num">{{ $money($invoice->amount) }}</td><td class="num"><strong>{{ $money($invoice->amount) }}</strong></td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="position: relative">
    @if ($receipt)
        <div class="stamp">LUNAS</div>
    @endif
    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $money($invoice->items->isNotEmpty() ? $invoice->subtotal() : $invoice->amount) }}</td></tr>
        @if ($hasDiscount)
            <tr><td>Diskon</td><td class="num">−{{ $money($invoice->discount_amount) }}</td></tr>
        @endif
        @if ($hasTax)
            <tr><td>PPN {{ rtrim(rtrim((string) $invoice->tax_rate, '0'), '.') }}%</td><td class="num">{{ $money($invoice->tax_amount) }}</td></tr>
        @endif
        <tr class="total"><td>Total</td><td class="num">{{ $money($invoice->amount) }}</td></tr>
    </table>
    </div>

    <div class="terbilang">Terbilang: {{ \App\Support\Terbilang::rupiah((string) $invoice->amount) }}</div>

    {{-- Pembayaran --}}
    @if ($receipt)
        <div class="box">
            <h3>Informasi pembayaran</h3>
            <table>
                <tr><td class="k">Metode</td><td>{{ $method ?? '–' }}</td></tr>
                <tr><td class="k">Waktu bayar</td><td>{{ $date($invoice->paid_at, 'j F Y, H.i T') }}</td></tr>
                @if ($invoice->proof_sender)
                    <tr><td class="k">Pengirim</td><td>{{ $invoice->proof_sender }}</td></tr>
                @endif
                @if ($invoice->gateway_order_id && $invoice->paid_via === 'midtrans')
                    <tr><td class="k">Referensi</td><td class="mono">{{ $invoice->gateway_order_id }}</td></tr>
                @endif
            </table>
        </div>
    @elseif ($invoice->status === \App\Enums\InvoiceStatus::Pending)
        <div class="box">
            <h3>Cara pembayaran</h3>
            <table>
                <tr><td class="k">Online</td><td>Pengaturan → Langganan → Bayar sekarang (QRIS, virtual account, atau transfer manual)</td></tr>
                @if ($manual !== [])
                    <tr><td class="k">Transfer ke</td><td>
                        <table class="w-full">
                            @foreach ($manual as $account)
                                @php($bank = $banks->get($account['bank_id'] ?? ''))
                                <tr class="bank-row">
                                    <td style="width: 18mm"><span class="bank-chip" style="background: {{ $bank->color ?? '#2D4282' }}; color: {{ $bank->text_color ?? '#ffffff' }}">{{ $bank->short_name ?? $account['bank'] }}</span></td>
                                    <td><span class="mono"><strong>{{ trim(chunk_split($account['number'], 4, ' ')) }}</strong></span> · a.n. {{ $account['holder'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td></tr>
                @endif
                <tr><td class="k">Berita transfer</td><td class="mono">{{ $invoice->number }}</td></tr>
            </table>
        </div>
    @endif

    @if ($invoice->memo && ! $receipt)
        <div class="note"><strong>Catatan:</strong> {{ $invoice->memo }}</div>
    @endif

    <div class="note">{{ $receipt ? 'Terima kasih, pembayaran Anda telah kami terima.' : 'Mohon lakukan pembayaran sebelum jatuh tempo agar layanan tidak terhenti.' }} Pertanyaan tagihan: {{ $issuer['email'] ?? 'tim gs.POS' }}.</div>
</body>
</html>
