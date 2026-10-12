{{--
    Kartu QR meja untuk dicetak (ADR 0014). Halaman mandiri seperti cetak struk; teks pengguna di-escape ({{ }}),
    SVG QR dibuat server (QrSvg) dari URL pendek kita sendiri. Nama panjang dipotong (…) agar kartu tetap rapi.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>QR meja · {{ $business }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f4; color: #1b2a55; font: 14px/1.35 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .toolbar { max-width: 190mm; margin: 24px auto 0; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .toolbar p { margin: 0; color: #5b6478; font-size: 13px; }
        .toolbar button { padding: 9px 18px; border: 0; border-radius: 8px; background: #2d4282; color: #fff; font: 600 13px system-ui, sans-serif; cursor: pointer; }
        .sheet { display: grid; grid-template-columns: repeat(3, 60mm); gap: 6mm; justify-content: center; max-width: 190mm; margin: 16px auto 24px; }
        .card { width: 60mm; overflow: hidden; border: 1px solid #d6dae3; border-radius: 4mm; background: #fff; text-align: center; break-inside: avoid; }
        .band { padding: 3mm 4mm; background: #1b2a55; color: #fff; }
        .business, .outlet { overflow: hidden; margin: 0; text-overflow: ellipsis; white-space: nowrap; }
        .business { font-size: 12px; font-weight: 700; letter-spacing: .02em; }
        .outlet { margin-top: .5mm; color: #b9c3dc; font-size: 9.5px; }
        .body { padding: 4mm 4mm 3.5mm; }
        .table { overflow: hidden; margin: 0; font-size: 22px; font-weight: 800; line-height: 1.1; text-overflow: ellipsis; white-space: nowrap; }
        .area { overflow: hidden; margin: 1mm 0 0; color: #5b6478; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
        .qr svg { display: block; width: 40mm; height: 40mm; margin: 3mm auto 2mm; }
        .hint { margin: 0; font-size: 11px; font-weight: 700; }
        .short { display: inline-block; margin-top: 1.5mm; padding: .8mm 2.5mm; border-radius: 999px; background: #eef1f8; color: #2d4282; font: 600 9.5px ui-monospace, SFMono-Regular, Menlo, monospace; }
        .empty { max-width: 190mm; margin: 48px auto; text-align: center; color: #5b6478; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0 auto; }
            .band { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .short { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p>{{ count($cards) }} kartu · 3 per baris di kertas A4. Gunting mengikuti garis kartu.</p>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    @if ($cards === [])
        <p class="empty">Belum ada meja aktif. Tambah meja di menu Meja &amp; QR.</p>
    @else
        <main class="sheet">
            @foreach ($cards as $card)
                <section class="card">
                    <div class="band">
                        <p class="business" title="{{ $business }}">{{ $business }}</p>
                        <p class="outlet" title="{{ $card['outlet'] }}">{{ $card['outlet'] }}</p>
                    </div>
                    <div class="body">
                        <p class="table" title="{{ $card['name'] }}">{{ $card['name'] }}</p>
                        @if ($card['area'])
                            <p class="area">{{ $card['area'] }}</p>
                        @endif
                        <div class="qr">{!! $card['svg'] !!}</div>
                        <p class="hint">Scan untuk pesan dari HP</p>
                        <span class="short">{{ $card['short'] }}</span>
                    </div>
                </section>
            @endforeach
        </main>
    @endif
</body>
</html>
