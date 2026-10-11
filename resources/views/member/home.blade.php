@php($fmt = fn (int $n): string => number_format($n, 0, ',', '.'))
<x-member.layout title="Beranda" active="home">
    <section class="member-card member-hello">
        <p class="member-muted">Halo,</p>
        <h1 class="member-title">{{ $member->name }}</h1>
        <div class="member-balance">
            <div>
                <span class="member-balance-value">{{ $fmt($member->points_balance) }}</span>
                <span class="member-balance-label">poin</span>
            </div>
            <span class="member-muted">≈ {{ \App\Support\Money::format(\App\Support\Money::mul($settings->redeem_value, max(0, $member->points_balance))) }}</span>
        </div>
        @if ($expiring)
            <p class="member-muted">{{ $fmt($expiring->remaining) }} poin berlaku s/d {{ $expiring->expires_at?->locale('id')->translatedFormat('j F Y') }}</p>
        @endif
    </section>

    <section class="member-card member-qr" data-code-url="{{ route('member.code', ['business' => $business->slug]) }}">
        <h2>Tunjukkan ke kasir</h2>
        <div class="member-qr-image" data-qr>{!! $proof['svg'] !!}</div>
        <p class="member-qr-code" data-code>{{ $proof['payload'] }}</p>
        <p class="member-muted">Kode berganti dalam <strong data-seconds>{{ $proof['seconds_left'] }}</strong> detik. Untuk memasang member & menukar poin.</p>
    </section>

    <script>
        // Kode QR dinamis: hitung mundur, ambil kode baru saat jendela berganti (tanpa muat ulang halaman)
        (() => {
            const box = document.querySelector('[data-code-url]');
            const seconds = box.querySelector('[data-seconds]');
            let left = Number(seconds.textContent);

            const refresh = async () => {
                const response = await fetch(box.dataset.codeUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (! response.ok) return;
                const data = await response.json();
                box.querySelector('[data-qr]').innerHTML = data.svg;
                box.querySelector('[data-code]').textContent = data.payload;
                left = data.seconds_left;
            };

            setInterval(() => {
                left -= 1;
                if (left <= 0) {
                    refresh();
                    left = 30;
                }
                seconds.textContent = left;
            }, 1000);
        })();
    </script>
</x-member.layout>
