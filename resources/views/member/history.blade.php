@php($tz = 'Asia/Jakarta')
<x-member.layout title="Riwayat" active="history">
    <section class="member-card">
        <h1 class="member-title">Riwayat poin</h1>
        <ul class="member-list">
            @forelse ($entries as $entry)
                <li>
                    <div>
                        <strong>{{ $entry->type->getLabel() }}</strong>
                        <span class="member-muted">{{ $entry->created_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }}</span>
                    </div>
                    <span @class(['member-points', 'is-minus' => $entry->points < 0])>{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points, 0, ',', '.') }}</span>
                </li>
            @empty
                <li class="member-muted">Belum ada mutasi poin.</li>
            @endforelse
        </ul>
    </section>

    <section class="member-card">
        <h2 class="member-subtitle">Transaksi terakhir</h2>
        <ul class="member-list">
            @forelse ($orders as $order)
                <li>
                    <div>
                        <strong>{{ $order->outlet?->name }}</strong>
                        <span class="member-muted">{{ $order->created_at->setTimezone($order->outlet?->timezone ?? $tz)->locale('id')->translatedFormat('j M Y, H.i') }} · {{ $order->status->getLabel() }}</span>
                    </div>
                    <span>{{ \App\Support\Money::format($order->grand_total) }}</span>
                </li>
            @empty
                <li class="member-muted">Belum ada transaksi.</li>
            @endforelse
        </ul>
    </section>
</x-member.layout>
