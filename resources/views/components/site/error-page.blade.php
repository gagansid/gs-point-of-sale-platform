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
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: var(--bg); color: var(--text);
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        .card {
            width: 100%; max-width: 440px; padding: 40px 32px; text-align: center;
            background: #fff; border: 1px solid var(--border); border-radius: 16px;
            box-shadow: 0 1px 2px rgb(16 24 40 / 0.04), 0 12px 32px rgb(27 42 85 / 0.06);
        }
        .logo { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .logo svg { width: 30px; height: 30px; }
        .logo .frame { stroke: var(--navy); }
        .logo .accent { stroke: var(--gray); }
        .logo .dot { fill: var(--primary); }
        .word { color: var(--navy); font-family: 'Nunito', ui-rounded, system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
        .word span { color: var(--primary); }
        .code {
            margin: 28px 0 4px; font-size: 64px; line-height: 1; font-weight: 800; letter-spacing: -0.04em;
            color: var(--primary); font-variant-numeric: tabular-nums;
        }
        h1 { margin: 12px 0 8px; font-size: 20px; line-height: 1.3; color: var(--navy); }
        p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.6; }
        .btn {
            display: inline-block; margin-top: 28px; padding: 11px 20px; border-radius: 10px;
            background: var(--primary); color: #fff; font-weight: 600; font-size: 14px; text-decoration: none;
        }
        .btn:hover, .btn:focus-visible { background: var(--navy); }
        .btn:focus-visible { outline: 3px solid var(--mist); outline-offset: 2px; }
        .ref {
            margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);
            color: var(--gray); font-size: 12px;
        }
        .ref code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted); word-break: break-all; }
        .help { margin-top: 12px; font-size: 13px; }
    </style>
</head>
<body>
    <main class="card" role="main">
        <a href="{{ url('/') }}" class="logo" aria-label="gs.POS">
            <svg viewBox="9.75 9.75 44.5 44.75" aria-hidden="true">
                <rect class="frame" x="14" y="14" width="36" height="27" rx="5" fill="none" stroke-width="4.5" />
                <path class="accent" d="M22.5 27.5h9" stroke-width="4.5" stroke-linecap="round" />
                <circle class="dot" cx="40.5" cy="27.5" r="3" />
                <path class="accent" d="M22 50h20" stroke-width="5" stroke-linecap="round" />
            </svg>
            <span class="word"><span>gs</span>.POS</span>
        </a>

        <div class="code" aria-hidden="true">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        {{ $slot }}

        <a href="{{ $actionUrl ?? url('/') }}" class="btn">{{ $actionLabel }}</a>

        @if (is_string($requestId) && $requestId !== '')
            <div class="ref">
                ID permintaan: <code>{{ $requestId }}</code>
                <p class="help">Sertakan ID ini saat menghubungi tim gs.POS.</p>
            </div>
        @endif
    </main>
</body>
</html>
