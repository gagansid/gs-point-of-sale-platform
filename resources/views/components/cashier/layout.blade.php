{{-- Kerangka kasir web (Q54): header outlet/kasir/navigasi + konten + toast. Halaman login & pilih outlet memakai x-cashier.auth. --}}
@props(['title' => 'Kasir web', 'active' => null, 'wide' => false])
@php
    $user = \App\Support\WebCashier::user();
    $outlet = \App\Support\CurrentOutlet::get();
    $links = [
        'pos' => ['Transaksi', route('cashier.home')],
        'orders' => ['Riwayat', route('cashier.orders')],
        'shift' => ['Shift', route('cashier.shift')],
    ];
    // Pesanan meja (ADR 0014): hanya bila self-order aktif di outlet ini
    $selfOrder = $outlet !== null && ($user?->can('self_order.handle') ?? false) && \App\Models\SelfOrderSetting::forOutlet($outlet->id)->enabled;
    // Kasbon (ADR 0023): bila aktif atau masih ada saldo
    if (($user?->can('member.view') ?? false) && \App\Models\MemberSetting::current()->credit_enabled) {
        $links['receivables'] = ['Kasbon', route('cashier.receivables')];
    }
    if ($selfOrder) {
        $links = ['pos' => $links['pos'], 'self-orders' => ['Pesanan meja', route('cashier.self-orders')], ...$links];
    }
@endphp
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
<body class="site-body cashier-body">
    <header class="cashier-header">
        <a href="{{ route('cashier.home') }}" class="cashier-brand" aria-label="Kasir web"><x-site.logo /></a>
        <div class="cashier-context">
            <strong>{{ $outlet?->name }}</strong>
            <span>{{ $user?->name }}</span>
        </div>
        <nav class="cashier-nav" aria-label="Menu kasir">
            @foreach ($links as $key => [$label, $url])
                <a href="{{ $url }}" @class(['is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'self-orders')<livewire:cashier.self-order-badge />@endif</a>
            @endforeach
        </nav>
        {{-- Kunci layar → kasir lain masuk dengan PIN, shift berlanjut tanpa hitung uang (ADR 0017) --}}
        <form method="POST" action="{{ route('cashier.lock') }}" class="cashier-logout" data-lock-form>
            @csrf
            <button type="submit" class="site-link-button" title="Kunci layar untuk ganti kasir">Kunci</button>
        </form>
        <form method="POST" action="{{ route('cashier.logout') }}" class="cashier-logout">
            @csrf
            <button type="submit" class="site-link-button">Keluar</button>
        </form>
    </header>

    @foreach (\App\Models\Announcement::current() as $announcement)
        <div class="cashier-announcement" role="status">
            <strong>{{ $announcement->title }}</strong> {{ $announcement->body }}
        </div>
    @endforeach

    <main @class(['cashier-main', 'cashier-main-wide' => $wide])>
        {{ $slot }}
    </main>

    {{-- Toast kanan atas (seperti notifikasi dashboard): flash server & event Livewire "cashier-toast" --}}
    <div class="cashier-toasts" data-toasts aria-live="polite"></div>

    <script>
        (() => {
            const container = document.querySelector('[data-toasts]');
            const icons = {
                success: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                info: 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
                warning: 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
                danger: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
            };

            // Pesan gagal tampil lebih lama; arahkan kursor ke toast untuk menahannya
            window.cashierToast = (message, tone = 'success') => {
                tone = icons[tone] ? tone : 'success';
                const toast = document.createElement('div');
                toast.className = `cashier-toast is-${tone}`;
                toast.setAttribute('role', tone === 'success' || tone === 'info' ? 'status' : 'alert');
                toast.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${icons[tone]}"/></svg>`
                    + '<p></p><button type="button" aria-label="Tutup">&times;</button>';
                toast.querySelector('p').textContent = message;

                const close = () => {
                    toast.classList.add('is-leaving');
                    setTimeout(() => toast.remove(), 200);
                };
                let timer = setTimeout(close, tone === 'success' || tone === 'info' ? 4000 : 7000);
                toast.addEventListener('mouseenter', () => clearTimeout(timer));
                toast.addEventListener('mouseleave', () => { timer = setTimeout(close, 2500); });
                toast.querySelector('button').addEventListener('click', close);

                // Tepat di bawah header (tinggi header berubah di layar sempit) agar menu & Keluar tetap terlihat
                const header = document.querySelector('.cashier-header');
                container.style.top = `${Math.max(0, header?.getBoundingClientRect().bottom ?? 0) + 12}px`;
                container.append(toast);
                // Maksimal 4 toast sekaligus
                [...container.children].slice(0, -4).forEach((old) => old.remove());
            };

            window.addEventListener('cashier-toast', (event) => {
                const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
                window.cashierToast(detail.message, detail.tone);
            });

            @if (session('status'))
                window.cashierToast(@js(session('status')), 'success');
            @endif
            @if ($errors->has('cashier'))
                window.cashierToast(@js($errors->first('cashier')), 'danger');
            @endif
        })();

        // Kunci otomatis (ADR 0017): tanpa ketikan/sentuhan/klik selama auto_lock_minutes → layar PIN.
        // Polling Livewire bukan aktivitas pengguna, jadi tidak menunda kunci.
        (() => {
            const minutes = @js($outlet?->auto_lock_minutes);
            const form = document.querySelector('[data-lock-form]');
            if (! minutes || ! form) return;
            let timer;
            const reset = () => {
                clearTimeout(timer);
                timer = setTimeout(() => form.submit(), minutes * 60 * 1000);
            };
            ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach((name) => document.addEventListener(name, reset, { passive: true }));
            reset();
        })();

        // Konfirmasi dua langkah tanpa dialog bawaan browser (bisa diblokir browser tertanam):
        // klik pertama mengganti label tombol, klik kedua dalam 5 detik baru mengirim form.
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-confirm-step]');
            if (! button) return;
            if (button.dataset.armed === '1') return;
            event.preventDefault();
            button.dataset.armed = '1';
            button.dataset.label ??= button.textContent;
            button.textContent = button.dataset.confirmStep;
            button.classList.add('is-armed');
            setTimeout(() => {
                button.dataset.armed = '';
                button.textContent = button.dataset.label;
                button.classList.remove('is-armed');
            }, 5000);
        });
    </script>
</body>
</html>
