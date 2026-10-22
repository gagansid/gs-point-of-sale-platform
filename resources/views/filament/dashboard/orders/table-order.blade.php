{{-- Pesanan meja → Detail (ADR 0014): meta, item, catatan; tautan ke order bila sudah diterima kasir. --}}
@php
    /** @var \App\Models\SelfOrder $selfOrder */
    /** @var list<array{name: string, qty: int, options: list<string>, notes: string|null}> $lines */
    $tz = \App\Support\CurrentOutlet::timezone();
    $when = $selfOrder->created_at->setTimezone($tz)->locale('id');
    $handledAt = $selfOrder->handled_at?->setTimezone($tz)->locale('id');
@endphp
<div class="gs-audit-detail">
    <dl class="gs-nota-meta">
        <div><dt>Waktu pesan</dt><dd>{{ $when->translatedFormat('l, j F Y') }}<br><span class="gs-audit-sub">pukul {{ $when->format('H.i') }}</span></dd></div>
        <div><dt>Status</dt><dd><x-filament::badge :color="$selfOrder->status->getColor()">{{ $selfOrder->status->getLabel() }}</x-filament::badge></dd></div>
        <div><dt>Meja</dt><dd>{{ $selfOrder->diningTable->name ?? '—' }}@if ($selfOrder->diningTable?->area)<br><span class="gs-audit-sub">{{ $selfOrder->diningTable->area }}</span>@endif</dd></div>
        <div><dt>Pelanggan</dt><dd>{{ $selfOrder->customer_name }}@if ($selfOrder->member)<br><span class="gs-audit-sub">Member · {{ $selfOrder->member->name }}</span>@endif</dd></div>
        @if ($selfOrder->handler)
            <div><dt>Ditangani</dt><dd>{{ $selfOrder->handler->name }}@if ($handledAt)<br><span class="gs-audit-sub">pukul {{ $handledAt->format('H.i') }}</span>@endif</dd></div>
        @endif
        @if (filled($selfOrder->voucher_code))
            <div><dt>Voucher</dt><dd class="font-mono">{{ $selfOrder->voucher_code }}</dd></div>
        @endif
    </dl>

    @if ($selfOrder->status === \App\Enums\SelfOrderStatus::Rejected && filled($selfOrder->reject_reason))
        <div class="gs-callout is-danger">Ditolak: {{ $selfOrder->reject_reason }}</div>
    @endif

    @if (filled($selfOrder->notes))
        <div class="gs-callout is-info">Catatan: {{ $selfOrder->notes }}</div>
    @endif

    <table class="gs-detail-table">
        <thead>
            <tr><th>Item</th><th class="is-num">Qty</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
                <tr wire:key="so-line-{{ $index }}">
                    <td class="gs-detail-name">
                        {{ $line['name'] }}
                        @if ($line['options'] !== [] || filled($line['notes']))
                            <br><span class="gs-detail-sub">{{ collect([implode(', ', $line['options']), $line['notes'] !== null ? '“'.$line['notes'].'”' : null])->filter()->implode(' · ') }}</span>
                        @endif
                    </td>
                    <td class="is-num">{{ number_format($line['qty'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="is-strong">Perkiraan total</td>
                <td class="is-num is-strong">{{ \App\Support\Money::format($selfOrder->estimated_total) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($selfOrder->order)
        <div class="gs-audit-foot">
            <span>Order <span class="font-mono">{{ $selfOrder->order->order_number }}</span></span>
            @if ($orderUrl)
                <x-filament::link :href="$orderUrl" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm">Lihat penjualan</x-filament::link>
            @endif
        </div>
    @endif
</div>
