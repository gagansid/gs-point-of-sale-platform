{{-- Kerangka kasir web (Q54): header outlet/kasir/navigasi + konten + toast. Halaman login & pilih outlet memakai x-cashier.auth. --}}
@props(['title' => 'Kasir web', 'active' => null, 'wide' => false])
@php
    $user = \App\Support\WebCashier::user();
    $outlet = \App\Support\CurrentOutlet::get();
    $links = [
        'pos' => ['Transaksi', route('cashier.home'), 'heroicon-o-squares-2x2'],
        'orders' => ['Riwayat', route('cashier.orders'), 'heroicon-o-clock'],
        'shift' => ['Shift', route('cashier.shift'), 'heroicon-o-calculator'],
    ];
    // Pesanan meja (ADR 0014): hanya bila self-order aktif di outlet ini
    $selfOrder = $outlet !== null && ($user?->can('self_order.handle') ?? false) && \App\Models\SelfOrderSetting::forOutlet($outlet->id)->enabled;
    // Kasbon (ADR 0023): bila aktif atau masih ada saldo
    if (($user?->can('member.view') ?? false) && \App\Models\MemberSetting::current()->credit_enabled) {
        $links['receivables'] = ['Kasbon', route('cashier.receivables'), 'heroicon-o-credit-card'];
    }
    if ($selfOrder) {
        $links = ['pos' => $links['pos'], 'self-orders' => ['Pesanan meja', route('cashier.self-orders'), 'heroicon-o-qr-code'], ...$links];
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
{{-- Layar lebar (transaksi): body setinggi layar, konten mengisi sisa di bawah header & pengumuman --}}
<body @class(['site-body', 'cashier-body', 'cashier-body-fill' => $wide])>
    <header class="cashier-header">
        <a href="{{ route('cashier.home') }}" class="cashier-brand" aria-label="Kasir web"><x-site.logo /></a>
        <div class="cashier-context">
            <strong>{{ $outlet?->name }}</strong>
            <span>{{ $user?->name }}</span>
        </div>
        <nav class="cashier-nav" aria-label="Menu kasir">
            @foreach ($links as $key => [$label, $url, $icon])
                <a href="{{ $url }}" @class(['is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>@svg($icon, ['aria-hidden' => 'true'])<span>{{ $label }}</span>@if ($key === 'self-orders')<livewire:cashier.self-order-badge />@endif</a>
            @endforeach
        </nav>
        <div class="cashier-header-actions">
            {{-- Kunci layar → kasir lain masuk dengan PIN, shift berlanjut tanpa hitung uang (ADR 0017) --}}
            <form method="POST" action="{{ route('cashier.lock') }}" class="cashier-logout" data-lock-form
                data-confirm-title="Kunci layar?" data-confirm-ok="Kunci layar" data-confirm-icon="lock"
                data-confirm-text="Shift tetap berjalan. Kasir berikutnya cukup memilih nama dan memasukkan PIN untuk melanjutkan.">
                @csrf
                <button type="submit" class="site-link-button" title="Kunci layar untuk ganti kasir">@svg('heroicon-o-lock-closed', ['aria-hidden' => 'true'])<span>Kunci</span></button>
            </form>
            <form method="POST" action="{{ route('cashier.logout') }}" class="cashier-logout"
                data-confirm-title="Keluar dari kasir web?" data-confirm-ok="Keluar" data-confirm-icon="logout" data-confirm-tone="danger"
                data-confirm-text="Shift di browser ini tetap terbuka. Bila sudah selesai bertugas, tutup shift dulu dari menu Shift.">
                @csrf
                <button type="submit" class="site-link-button" title="Keluar">@svg('heroicon-o-arrow-right-start-on-rectangle', ['aria-hidden' => 'true'])<span>Keluar</span></button>
            </form>
        </div>
    </header>

    @foreach (\App\Models\Announcement::current() as $announcement)
        <div class="cashier-announcement" role="status">
            <strong>{{ $announcement->title }}</strong> {{ $announcement->body }}
        </div>
    @endforeach

    <main @class(['cashier-main', 'cashier-main-wide' => $wide])>
        {{ $slot }}
    </main>

    {{-- Konfirmasi Kunci & Keluar: <dialog> bawaan (tanpa Alpine; kunci otomatis memanggil form.submit() tanpa dialog) --}}
    <dialog class="cashier-confirm" data-confirm-dialog aria-labelledby="cashier-confirm-title">
        <form method="dialog">
            <span class="cashier-confirm-icon" data-confirm-icon-slot="lock" aria-hidden="true">@svg('heroicon-o-lock-closed')</span>
            <span class="cashier-confirm-icon is-danger" data-confirm-icon-slot="logout" aria-hidden="true">@svg('heroicon-o-arrow-right-start-on-rectangle')</span>
            <span class="cashier-confirm-icon" data-confirm-icon-slot="cash" aria-hidden="true">@svg('heroicon-o-banknotes')</span>
            <span class="cashier-confirm-icon" data-confirm-icon-slot="merge" aria-hidden="true">@svg('heroicon-o-square-2-stack')</span>
            <span class="cashier-confirm-icon is-danger" data-confirm-icon-slot="warning" aria-hidden="true">@svg('heroicon-o-exclamation-triangle')</span>
            <h2 id="cashier-confirm-title" data-confirm-title></h2>
            <p data-confirm-text></p>
            <div class="cashier-confirm-actions">
                <button type="submit" value="cancel" class="site-btn site-btn-outline" autofocus>Batal</button>
                <button type="submit" value="ok" class="site-btn site-btn-primary" data-confirm-ok></button>
            </div>
        </form>
    </dialog>

    {{-- Toast kanan atas (seperti notifikasi dashboard): flash server & event Livewire "cashier-toast" --}}
    <div class="cashier-toasts" data-toasts aria-live="polite"></div>

    <script>
        // Input nominal Rupiah: tampil "113.100,5", nilai terkirim "113100.5".
        // Titik = ribuan, koma = desimal (maks. 2 digit), sama dengan format Rp di layar & struk.
        const posMoneyFormat = (() => {
            const group = (digits) => digits.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            // "113100.00" → "113.100"; "113100.5" → "113.100,5"
            const fromRaw = (raw) => {
                if (raw === null || raw === undefined || raw === '' || isNaN(Number(raw))) return '';
                const [int, dec = ''] = String(raw).split('.');
                const cents = dec.replace(/0+$/, '');
                return group(int) + (cents ? ',' + cents : '');
            };

            // Ketikan "113.100,5" → { raw: "113100.5", display: "113.100,5" }; kosong → raw ""
            const parse = (text) => {
                const cleaned = text.replace(/[^\d,]/g, '');
                const [int, ...rest] = cleaned.split(',');
                const hasComma = cleaned.includes(',');
                const dec = rest.join('').slice(0, 2);
                const intDigits = int.replace(/^0+(?=\d)/, '');
                if (intDigits === '' && ! hasComma) return { raw: '', display: '' };

                return {
                    raw: (intDigits || '0') + (dec ? '.' + dec : ''),
                    display: group(intDigits || '0') + (hasComma ? ',' + dec : ''),
                };
            };

            return { fromRaw, parse };
        })();

        // Form biasa (tanpa Livewire/Alpine): <span data-money> berisi input teks + hidden [data-money-raw] bernama field asli
        (() => {
            const sync = (box, raw) => {
                box.querySelector('[data-money-raw]').value = raw;
                box.querySelector('input[type="text"]').value = posMoneyFormat.fromRaw(raw);
            };
            document.querySelectorAll('[data-money]').forEach((box) => sync(box, box.querySelector('[data-money-raw]').value));
            document.addEventListener('input', (event) => {
                const box = event.target.closest('[data-money]');
                if (! box || event.target.type !== 'text') return;
                const { raw, display } = posMoneyFormat.parse(event.target.value);
                event.target.value = display;
                box.querySelector('[data-money-raw]').value = raw;
            });
            // Tombol isi cepat (mis. "Penuh"): mengisi field uang di form yang sama
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-money-fill]');
                const box = button?.form?.querySelector('[data-money]');
                if (! box) return;
                sync(box, button.dataset.moneyFill);
                box.querySelector('input[type="text"]').focus();
            });
        })();

        document.addEventListener('alpine:init', () => {
            const { fromRaw, parse } = posMoneyFormat;

            // x-modelable + wire:model: nilai Livewire "113100.5"
            window.Alpine.data('posMoney', () => ({
                raw: '',
                display: '',
                typed: null,
                init() {
                    this.display = fromRaw(this.raw);
                    // Nilai dari server (Uang pas, nominal cepat, reset): perbarui teks; ketikan sendiri tidak ditimpa
                    this.$watch('raw', (value) => {
                        if (value !== this.typed) this.display = fromRaw(value);
                    });
                },
                type(event) {
                    ({ raw: this.typed, display: this.display } = parse(event.target.value));
                    event.target.value = this.display;
                    this.raw = this.typed;
                },
            }));
        });

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

        // Kunci & Keluar: tampilkan dialog konfirmasi; "ok" baru mengirim form
        (() => {
            const dialog = document.querySelector('[data-confirm-dialog]');
            if (! dialog || typeof dialog.showModal !== 'function') return;
            let pending = null;

            document.querySelectorAll('form[data-confirm-title]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    pending = form;
                    dialog.querySelector('[data-confirm-title]').textContent = form.dataset.confirmTitle;
                    // {amount} = nominal yang sedang diketik di field uang form ini (mis. pelunasan kasbon)
                    const amount = form.querySelector('[data-money] input[type="text"]')?.value || '0';
                    dialog.querySelector('[data-confirm-text]').textContent = (form.dataset.confirmText ?? '').replace('{amount}', amount);
                    const ok = dialog.querySelector('[data-confirm-ok]');
                    ok.textContent = form.dataset.confirmOk ?? 'Lanjut';
                    ok.classList.toggle('pos-btn-danger', form.dataset.confirmTone === 'danger');
                    dialog.querySelectorAll('[data-confirm-icon-slot]').forEach((icon) => {
                        icon.hidden = icon.dataset.confirmIconSlot !== form.dataset.confirmIcon;
                    });
                    dialog.returnValue = '';
                    dialog.showModal();
                });
            });

            // Klik di luar kartu = batal
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close('cancel');
            });
            dialog.addEventListener('close', () => {
                if (dialog.returnValue === 'ok' && pending) pending.submit();
                pending = null;
            });
        })();

        // Stepper qty − n + (form biasa): [data-stepper] berisi input number + tombol [data-step], dibatasi min/max input
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-stepper] [data-step]');
            if (! button) return;
            const input = button.closest('[data-stepper]').querySelector('input');
            const next = (Number(input.value) || 0) + Number(button.dataset.step);
            input.value = Math.min(Number(input.max || Infinity), Math.max(Number(input.min || 0), next));
        });

        // Baris tabel yang bisa diklik (tr[data-href]); klik pada tautan/tombol di dalamnya tetap berjalan sendiri
        document.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-href]');
            if (! row || event.target.closest('a, button, input, select, label')) return;
            window.location.href = row.dataset.href;
        });

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
