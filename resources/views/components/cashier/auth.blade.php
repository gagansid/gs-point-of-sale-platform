{{-- Kerangka halaman masuk kasir web (login, masuk ulang, pilih outlet): latar brand + kartu ringkas + versi. --}}
@props(['title' => 'Kasir web'])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — gs.POS</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body">
    <main class="cashier-auth">
        <div class="cashier-auth-inner">
            {{-- Panel brand: hanya di layar lebar --}}
            <section class="cashier-auth-brand" aria-hidden="true">
                <x-site.logo light />
                <h2>Siap melayani dari browser mana pun</h2>
                <p>Satu layar untuk mencatat pesanan, menerima pembayaran, dan menutup shift.</p>
                <ul>
                    <li><x-site.icon name="clock" /> Shift & kas tercatat rapi</li>
                    <li><x-site.icon name="card" /> Tunai, QRIS, kartu, atau bayar terpisah</li>
                    <li><x-site.icon name="receipt" /> Cetak struk langsung dari browser</li>
                </ul>
            </section>

            <div class="cashier-auth-column">
                <div class="cashier-auth-card">
                    <span class="cashier-auth-logo"><x-site.logo /></span>
                    {{ $slot }}
                </div>
                <p class="cashier-auth-version">gs.POS Kasir web · v{{ config('app.version') }}</p>
            </div>
        </div>
    </main>
    <script>
        // Ganti tab cara masuk → fokus ke field pertama yang terlihat & masih kosong
        document.querySelectorAll('.cashier-tabs input[type=radio]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const fields = [...radio.form.querySelectorAll('input:not([type=radio]):not([type=hidden])')];
                (fields.find((f) => f.offsetParent !== null && f.value === '') ?? fields.find((f) => f.offsetParent !== null))?.focus();
            });
        });
    </script>
</body>
</html>
