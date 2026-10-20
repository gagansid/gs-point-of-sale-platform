{{-- Buat tagihan → ringkasan (InvoiceQuote): total besar + jenis & efek. --}}
@php /** @var \App\Services\Billing\InvoiceQuote|null $quote */ @endphp
<div class="gs-quote">
    @if ($quote)
        <div class="gs-quote-head">
            <span class="gs-quote-kind">{{ $quote->kind->getLabel() }}</span>
            <span class="gs-quote-amount">{{ \App\Support\Money::format($quote->amount) }}</span>
        </div>
    @endif
    <p class="gs-quote-text">{{ $text }}</p>
</div>
