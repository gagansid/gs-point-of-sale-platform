@php($tz = 'Asia/Jakarta')
<x-member.layout title="Riwayat" active="history">
    <section class="so-card">
        <h1 class="so-card-title">Riwayat poin</h1>
        @if ($entries->isEmpty())
            <div class="so-empty">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-star')</span>
                <p class="so-empty-title">Belum ada mutasi poin</p>
                <p>Poin bertambah setiap kali belanja sebagai member.</p>
            </div>
        @else
            <ul class="so-list">
                @foreach ($entries as $entry)
                    <li>
                        <div class="so-list-main">
                            <strong>{{ $entry->type->getLabel() }}</strong>
                            <span class="so-muted">{{ $entry->created_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }}</span>
                        </div>
                        <span @class(['so-list-end', 'is-plus' => $entry->points > 0, 'is-minus' => $entry->points < 0])>{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points, 0, ',', '.') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="so-card">
        <h2 class="so-card-title">Transaksi terakhir</h2>
        @if ($orders->isEmpty())
            <div class="so-empty">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-receipt-percent')</span>
                <p class="so-empty-title">Belum ada transaksi</p>
                <p>Transaksi sebagai member akan muncul di sini.</p>
            </div>
        @else
            <ul class="so-list">
                @foreach ($orders as $order)
                    <li>
                        <div class="so-list-main">
                            <strong>{{ $order->outlet?->name }}</strong>
                            <span class="so-muted">{{ $order->created_at->setTimezone($order->outlet?->timezone ?? $tz)->locale('id')->translatedFormat('j M Y, H.i') }} · {{ $order->status->getLabel() }}</span>
                        </div>
                        <span class="so-list-end">{{ \App\Support\Money::format($order->grand_total) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-member.layout>
