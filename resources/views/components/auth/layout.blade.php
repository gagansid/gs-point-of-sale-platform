{{--
    Kerangka semua halaman masuk (owner, lupa/atur kata sandi, kasir web): latar navy brand + pola titik,
    panel brand di layar lebar, kartu putih berisi form, dan versi aplikasi di bawah kartu.
--}}
@props([
    'title' => 'Masuk',
    'app' => 'Dashboard',
    'heading' => 'Kelola bisnis dari mana saja',
    'text' => 'Pantau penjualan, stok, dan karyawan semua outlet dalam satu dashboard.',
    'points' => [['chart', 'Laporan penjualan real-time'], ['cube', 'Stok & bahan baku terkendali'], ['users', 'Karyawan, shift, dan hak akses']],
    'home' => null,
    // false = tanpa panel brand: kartu saja di tengah, logo di dalam kartu (mis. halaman daftar)
    'brand' => true,
])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — gs.POS</title>
    <link rel="icon" href="{{ asset('images/brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body">
    <main class="cashier-auth">
        <div @class(['cashier-auth-inner', 'is-single' => ! $brand])>
            {{-- Panel brand: hanya di layar lebar --}}
            @if ($brand)
            <section class="cashier-auth-brand" aria-hidden="true">
                <x-site.logo light />
                <h2>{{ $heading }}</h2>
                <p>{{ $text }}</p>
                <ul>
                    @foreach ($points as [$icon, $label])
                        <li><x-site.icon :name="$icon" /> {{ $label }}</li>
                    @endforeach
                </ul>
            </section>
            @endif

            <div class="cashier-auth-column">
                <div class="cashier-auth-card">
                    @if ($home)
                        <a href="{{ $home }}" class="cashier-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>
                    @else
                        <span class="cashier-auth-logo"><x-site.logo /></span>
                    @endif
                    {{ $slot }}
                </div>
                <p class="cashier-auth-version">gs.POS {{ $app }} · v{{ config('app.version') }}</p>
            </div>
        </div>
    </main>
    <script>
        // Tombol mata: tampilkan/sembunyikan kata sandi (tanpa border, di dalam field)
        document.querySelectorAll('[data-auth-reveal]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.authReveal);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                input.focus();
            });
        });
        // Ganti tab cara masuk → fokus ke field pertama yang terlihat & masih kosong
        document.querySelectorAll('.cashier-tabs input[type=radio]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const fields = [...radio.form.querySelectorAll('input:not([type=radio]):not([type=hidden]):not([type=checkbox])')];
                (fields.find((f) => f.offsetParent !== null && f.value === '') ?? fields.find((f) => f.offsetParent !== null))?.focus();
            });
        });
        // Cegah kirim ganda
        document.querySelectorAll('form.cashier-auth-form').forEach((form) => {
            form.addEventListener('submit', () => form.querySelector('[type=submit]')?.setAttribute('aria-busy', 'true'));
        });
    </script>
</body>
</html>
