@php($fmt = fn (int $n): string => number_format($n, 0, ',', '.'))
<x-member.layout title="Beranda" active="home">
    <section class="member-card member-hello">
        <p class="member-muted">Halo,</p>
        <h1 class="member-title">{{ $member->name }}@if ($tier) <span class="member-tier">{{ $tier->name }}</span>@endif</h1>
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

    @if ($nextTier)
        {{-- Progres tier (M5): belanja 12 bulan terakhir terhadap batas tier berikutnya --}}
        @php($spend = \App\Support\Money::fromDb($member->spend_12m))
        @php($percent = \App\Support\Money::isPositive($nextTier->min_spend_12m) ? min(100, (int) floor((float) bcdiv(bcmul($spend, '100', 2), $nextTier->min_spend_12m, 2))) : 100)
        <section class="member-card">
            <h2 class="member-subtitle">Menuju {{ $nextTier->name }}</h2>
            <progress class="member-progress" max="100" value="{{ $percent }}" aria-label="Progres ke tier {{ $nextTier->name }}">{{ $percent }}%</progress>
            <p class="member-muted">Belanja {{ \App\Support\Money::format(\App\Support\Money::sub($nextTier->min_spend_12m, $spend)) }} lagi dalam 12 bulan untuk naik ke {{ $nextTier->name }}.</p>
        </section>
    @endif

    @foreach ($stampCards as $card)
        {{-- Kartu stempel (M6): satu lingkaran per stempel menuju 1 gratis --}}
        @php($filled = $card['balance'] > 0 ? $card['balance'] % $card['stamps_required'] : 0)
        <section class="member-card">
            <h2 class="member-subtitle">{{ $card['name'] }}</h2>
            <ol class="member-stamps" aria-label="{{ $filled }} dari {{ $card['stamps_required'] }} stempel">
                @for ($i = 1; $i <= $card['stamps_required']; $i++)
                    <li @class(['is-filled' => $i <= $filled || $card['rewards_available'] > 0 && $filled === 0])>{{ $i }}</li>
                @endfor
            </ol>
            <p class="member-muted">
                @if ($card['rewards_available'] > 0)
                    {{ $card['rewards_available'] }} gratis siap dipakai — sebutkan ke kasir.
                @else
                    {{ $card['stamps_required'] - $filled }} stempel lagi untuk 1 gratis.
                @endif
            </p>
        </section>
    @endforeach

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
