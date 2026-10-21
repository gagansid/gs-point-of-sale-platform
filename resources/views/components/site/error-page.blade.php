{{--
    Halaman error web bermerek gs.POS (situs, dashboard, admin). API tetap JSON (ApiExceptionRenderer).

    Sengaja mandiri: CSS inline, logo SVG inline, tanpa @vite/route()/database — halaman 500/503 harus tetap
    tampil walau build aset, routing, atau DB yang bermasalah. Pesan exception tidak pernah ditampilkan
    (bisa membocorkan detail teknis); 5xx menampilkan ID permintaan untuk dilaporkan ke tim gs.POS.
--}}
@props([
    'code',
    'title',
    'message',
    'actionLabel' => 'Kembali ke beranda',
    'actionUrl' => null,
    'showRequestId' => false,
])

@php
    $requestId = $showRequestId ? \Illuminate\Support\Facades\Context::get('request_id') : null;
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} · {{ $title }} — gs.POS</title>
    <link rel="icon" href="/images/brand/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            --navy: #1b2a55; --primary: #2d4282; --primary-lt: rgb(45 66 130 / 0.08);
            --gray: #8a919e; --mist: #b8c0d4; --text: #1e2633; --muted: #626d7d;
            --border: #e6e7eb; --bg: #f5f7fb;
            --tone: var(--primary); --tone-lt: rgb(45 66 130 / 0.1);
        }
        .tone-warning { --tone: #b26b00; --tone-lt: rgb(245 159 0 / 0.14); }
        .tone-danger { --tone: #c03535; --tone-lt: rgb(214 57 57 / 0.12); }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 18px; padding: 24px; color: var(--text);
            /* Sama dengan latar halaman masuk: navy bergradasi + pola titik halus */
            background:
                radial-gradient(circle at 1px 1px, rgb(255 255 255 / 0.07) 1px, transparent 0) 0 0 / 22px 22px,
                radial-gradient(1200px 600px at 10% 0%, #34508f 0%, transparent 60%),
                linear-gradient(160deg, #24386f 0%, #1b2a55 55%, #141f40 100%);
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        .card {
            width: 100%; max-width: 440px; padding: 36px 32px 32px; text-align: center;
            background: #fff; border-radius: 16px;
            box-shadow: 0 24px 60px rgb(10 16 35 / 0.35), 0 0 0 1px rgb(255 255 255 / 0.06);
        }
        .logo { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .logo svg { width: 28px; height: 28px; }
        .logo .frame { stroke: var(--navy); }
        .logo .accent { stroke: var(--gray); }
        .logo .dot { fill: var(--primary); }
        .word { color: var(--navy); font-size: 19px; font-weight: 800; letter-spacing: -0.02em; }
        .word span { color: var(--gray); }
        .icon {
            display: grid; place-items: center; width: 56px; height: 56px; margin: 28px auto 0;
            border-radius: 16px; background: var(--tone-lt); color: var(--tone);
        }
        .icon svg { width: 26px; height: 26px; }
        .code {
            display: inline-block; margin-top: 16px; padding: 3px 10px; border-radius: 999px;
            background: var(--bg); color: var(--muted); font-size: 12px; font-weight: 600; letter-spacing: 0.04em;
        }
        h1 { margin: 12px 0 8px; font-size: 21px; line-height: 1.3; color: var(--navy); letter-spacing: -0.01em; }
        p { margin: 0; color: var(--muted); font-size: 14.5px; line-height: 1.6; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 28px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 20px;
            border: 1px solid transparent; border-radius: 10px; font: inherit; font-weight: 600; font-size: 14px;
            text-decoration: none; cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--navy); }
        .btn-ghost { background: #fff; border-color: #dcdee3; color: var(--text); }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }
        .btn:focus-visible { outline: 3px solid var(--mist); outline-offset: 2px; }
        .ref {
            margin-top: 24px; padding-top: 16px; border-top: 1px dashed var(--border);
            color: var(--gray); font-size: 12px;
        }
        .ref code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted); word-break: break-all; }
        .help { margin-top: 6px; font-size: 12.5px; }
        .foot { color: rgb(184 192 212 / 0.75); font-size: 12px; }
    </style>
</head>
<body>
    @php
        $codeNumber = (int) $code;
        // Ikon garis (Heroicons outline) & nada warna per jenis error
        $icon = match (true) {
            $codeNumber === 404 => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
            in_array($codeNumber, [401, 403], true) => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
            in_array($codeNumber, [419, 429], true) => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            $codeNumber === 503 => 'M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085',
            default => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        };
        $tone = match (true) {
            $codeNumber >= 500 && $codeNumber !== 503 => 'tone-danger',
            in_array($codeNumber, [419, 429, 503], true) => 'tone-warning',
            default => '',
        };
    @endphp
    <main class="card {{ $tone }}" role="main">
        <a href="{{ url('/') }}" class="logo" aria-label="gs.POS">
            <svg viewBox="9.75 9.75 44.5 44.75" aria-hidden="true">
                <rect class="frame" x="14" y="14" width="36" height="27" rx="5" fill="none" stroke-width="4.5" />
                <path class="accent" d="M22.5 27.5h9" stroke-width="4.5" stroke-linecap="round" />
                <circle class="dot" cx="40.5" cy="27.5" r="3" />
                <path class="accent" d="M22 50h20" stroke-width="5" stroke-linecap="round" />
            </svg>
            <span class="word"><span>gs</span>.POS</span>
        </a>

        <div class="icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
            </svg>
        </div>
        <div class="code">Kode {{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        {{ $slot }}

        <div class="actions">
            <a href="{{ $actionUrl ?? url('/') }}" class="btn btn-primary">{{ $actionLabel }}</a>
            {{-- Tanpa JavaScript tombol ini tidak tampil (noscript tidak perlu: tombol utama tetap ada) --}}
            <button type="button" class="btn btn-ghost" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')" hidden id="gs-back">Halaman sebelumnya</button>
        </div>

        @if (is_string($requestId) && $requestId !== '')
            <div class="ref">
                ID permintaan: <code>{{ $requestId }}</code>
                <p class="help">Sertakan ID ini saat menghubungi tim gs.POS.</p>
            </div>
        @endif
    </main>
    <div class="foot">gs.POS · Aplikasi kasir untuk kafe &amp; UMKM</div>
    <script>
        if (history.length > 1) { document.getElementById('gs-back').hidden = false; }
    </script>
</body>
</html>
