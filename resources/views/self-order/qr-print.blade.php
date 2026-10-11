{{--
    Kartu QR meja untuk dicetak (ADR 0014). Halaman mandiri seperti cetak struk; teks pengguna di-escape ({{ }}),
    SVG QR dibuat server (QrSvg) dari URL kita sendiri.
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
        body { margin: 0; background: #eef0f4; color: #1b2a55; font: 14px/1.4 system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(62mm, 1fr)); gap: 8mm; max-width: 200mm; margin: 24px auto; }
        .card { padding: 6mm; border: 1px dashed #9aa3b8; border-radius: 4mm; background: #fff; text-align: center; break-inside: avoid; }
        .business { margin: 0; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #5b6478; }
        .table { margin: 2mm 0 0; font-size: 22px; font-weight: 800; }
        .area { margin: 0; color: #5b6478; font-size: 12px; }
        .qr svg { width: 48mm; height: 48mm; margin: 3mm auto; display: block; }
        .hint { margin: 0; font-weight: 600; }
        .url { margin: 2mm 0 0; color: #5b6478; font-size: 9px; word-break: break-all; }
        .actions { max-width: 200mm; margin: 24px auto 0; display: flex; gap: 8px; }
        .actions button { padding: 8px 16px; border: 1px solid #2d4282; border-radius: 6px; background: #2d4282; color: #fff; font: 600 13px system-ui, sans-serif; cursor: pointer; }
        .empty { max-width: 200mm; margin: 48px auto; text-align: center; color: #5b6478; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Cetak</button></div>

    @if ($cards === [])
        <p class="empty">Belum ada meja aktif. Tambah meja di menu Meja &amp; QR.</p>
    @else
        <main class="sheet">
            @foreach ($cards as $card)
                <section class="card">
                    <p class="business">{{ $business }} · {{ $card['outlet'] }}</p>
                    <p class="table">{{ $card['name'] }}</p>
                    @if ($card['area'])
                        <p class="area">{{ $card['area'] }}</p>
                    @endif
                    <div class="qr">{!! $card['svg'] !!}</div>
                    <p class="hint">Scan untuk pesan dari HP</p>
                    <p class="url">{{ $card['url'] }}</p>
                </section>
            @endforeach
        </main>
    @endif
</body>
</html>
