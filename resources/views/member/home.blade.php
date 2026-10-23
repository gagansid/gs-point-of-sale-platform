@php($fmt = fn (int $n): string => number_format($n, 0, ',', '.'))
<x-member.layout title="Beranda" active="home">
    <section class="so-card">
        <div class="so-card-head">
            <div>
                <p class="so-muted">Halo,</p>
                <h1 class="so-page-title">{{ $member->name }}</h1>
            </div>
            @if ($tier)
                <span class="so-badge is-info">@svg('heroicon-s-star', ['aria-hidden' => 'true']) {{ $tier->name }}</span>
            @endif
        </div>
        <div class="so-points">
            <span class="so-points-value">{{ $fmt($member->points_balance) }}</span>
            <span class="so-secondary">poin</span>
            <span class="so-muted so-num">≈ {{ \App\Support\Money::format(\App\Support\Money::mul($settings->redeem_value, max(0, $member->points_balance))) }}</span>
        </div>
        @if ($expiring)
            <p class="so-muted">{{ $fmt($expiring->remaining) }} poin berlaku s/d {{ $expiring->expires_at?->locale('id')->translatedFormat('j F Y') }}</p>
        @endif
    </section>

    <section class="so-card so-qr" data-code-url="{{ route('member.code', ['business' => $business->slug]) }}">
        <h2 class="so-card-title">Tunjukkan ke kasir</h2>
        <div class="so-qr-box" data-qr>{!! $proof['svg'] !!}</div>
        <p class="so-mono" data-code>{{ $proof['payload'] }}</p>
        <p class="so-muted">Kode berganti dalam <strong class="so-num" data-seconds>{{ $proof['seconds_left'] }}</strong> detik. Untuk memasang member & menukar poin.</p>
    </section>

    @if ($nextTier)
        {{-- Progres tier (M5): belanja 12 bulan terakhir terhadap batas tier berikutnya --}}
        @php($spend = \App\Support\Money::fromDb($member->spend_12m))
        @php($percent = \App\Support\Money::isPositive($nextTier->min_spend_12m) ? min(100, (int) floor((float) bcdiv(bcmul($spend, '100', 2), $nextTier->min_spend_12m, 2))) : 100)
        <section class="so-card">
            <div class="so-card-head">
                <h2 class="so-card-title">Menuju {{ $nextTier->name }}</h2>
                <span class="so-muted so-num">{{ $percent }}%</span>
            </div>
            <progress class="so-progress" max="100" value="{{ $percent }}" aria-label="Progres ke tier {{ $nextTier->name }}">{{ $percent }}%</progress>
            <p class="so-muted">Belanja {{ \App\Support\Money::format(\App\Support\Money::sub($nextTier->min_spend_12m, $spend)) }} lagi dalam 12 bulan untuk naik ke {{ $nextTier->name }}.</p>
        </section>
    @endif

    @foreach ($vouchers as $voucher)
        {{-- Voucher pribadi (M7): disebutkan ke kasir, dipakai lewat member terpasang --}}
        <section class="so-card">
            <div class="so-card-head">
                <h2 class="so-card-title">{{ $voucher->name }}</h2>
                <span class="so-badge is-success">@svg('heroicon-o-gift', ['aria-hidden' => 'true']) Voucher</span>
            </div>
            <p class="so-highlight">{{ $voucher->benefitLabel() }}</p>
            <p class="so-muted">
                @if (\App\Support\Money::isPositive($voucher->min_spend))
                    Minimal belanja {{ \App\Support\Money::format($voucher->min_spend) }}.
                @endif
                @if ($voucher->ends_at)
                    Berlaku s/d {{ $voucher->ends_at->setTimezone(\App\Actions\Member\IssueBirthdayVouchers::TIMEZONE)->locale('id')->translatedFormat('j F Y') }}.
                @endif
                Sebutkan ke kasir saat membayar.
            </p>
        </section>
    @endforeach

    @foreach ($stampCards as $card)
        {{-- Kartu stempel (M6): satu lingkaran per stempel menuju 1 gratis --}}
        @php($filled = $card['balance'] > 0 ? $card['balance'] % $card['stamps_required'] : 0)
        <section class="so-card">
            <h2 class="so-card-title">{{ $card['name'] }}</h2>
            <ol class="so-stamps" aria-label="{{ $filled }} dari {{ $card['stamps_required'] }} stempel">
                @for ($i = 1; $i <= $card['stamps_required']; $i++)
                    <li @class(['is-filled' => $i <= $filled || $card['rewards_available'] > 0 && $filled === 0])>{{ $i }}</li>
                @endfor
            </ol>
            <p class="so-muted">
                @if ($card['rewards_available'] > 0)
                    {{ $card['rewards_available'] }} gratis siap dipakai — sebutkan ke kasir.
                @else
                    {{ $card['stamps_required'] - $filled }} stempel lagi untuk 1 gratis.
                @endif
            </p>
        </section>
    @endforeach

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
