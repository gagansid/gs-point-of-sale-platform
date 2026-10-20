{{-- Bayar tagihan → ringkasan di atas pilihan metode. --}}
@php
    /** @var \App\Models\SubscriptionInvoice|null $invoice */
    $tz = \App\Support\CurrentOutlet::timezone();
@endphp
@if ($invoice)
    <div class="gs-pay-summary">
        <div>
            <div class="gs-pay-summary-label">Total dibayar</div>
            <div class="gs-pay-summary-amount">{{ \App\Support\Money::format((string) $invoice->amount) }}</div>
            <div class="gs-pay-summary-sub">{{ $invoice->description() }}</div>
        </div>
        <dl>
            <div><dt>Nomor</dt><dd class="font-mono">{{ $invoice->number }}</dd></div>
            <div><dt>Jatuh tempo</dt><dd>{{ $invoice->due_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y') }}</dd></div>
        </dl>
    </div>
@endif
