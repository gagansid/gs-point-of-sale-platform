{{--
    QR meja tidak dikenal, sudah diganti, atau meja/outlet/bisnis nonaktif (ADR 0027 §5.9). Satu pesan untuk semua
    sebab agar kode lain tidak bisa ditebak; status HTTP 404.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <x-so.head title="Kode QR tidak aktif" />
</head>
<body class="so-body">
    <main class="so-main is-center">
        <section class="so-card">
            <div class="so-empty">
                <span class="so-empty-icon is-warning" aria-hidden="true">@svg('heroicon-o-qr-code')</span>
                <h1 class="so-empty-title">Kode QR tidak aktif</h1>
                <p>Kode QR meja ini sudah diganti atau mejanya sedang tidak dipakai. Minta kode QR terbaru atau pesan langsung di kasir.</p>
            </div>
        </section>
        <p class="so-muted so-foot">gs.POS</p>
    </main>
</body>
</html>
