{{-- Bayar tagihan → transfer manual: rekening gs.POS (salin nomor), QRIS statis, petunjuk. --}}
@php
    /** @var list<array{bank_id: string|null, bank: string, number: string, holder: string}> $accounts */
    /** @var \App\Models\SubscriptionInvoice|null $invoice */
    $banks = \App\Models\Bank::query()->whereKey(array_filter(array_column($accounts, 'bank_id')))->get()->keyBy('id');
@endphp
<div class="gs-bank-list">
    @foreach ($accounts as $account)
        <div class="gs-bank-item" wire:key="bank-{{ $loop->index }}">
            @php($bank = $banks->get($account['bank_id'] ?? ''))
            <x-bank-logo :bank="$bank" :name="$account['bank']" :size="40" class="gs-bank-logo" />
            <div class="gs-bank-body">
                <div class="gs-bank-number">{{ trim(chunk_split($account['number'], 4, ' ')) }}</div>
                <div class="gs-bank-holder">{{ $bank?->name ?? $account['bank'] }} · a.n. {{ $account['holder'] }}</div>
            </div>
            <button type="button" class="gs-copy-btn" x-data="{ copied: false }" x-tooltip.raw="Salin nomor rekening"
                aria-label="Salin nomor rekening {{ $bank?->short_name ?? $account['bank'] }}"
                x-on:click="navigator.clipboard.writeText(@js($account['number'])); copied = true; setTimeout(() => copied = false, 1500)"
                x-bind:class="{ 'is-copied': copied }">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentDuplicate" class="gs-copy-icon" x-show="! copied" />
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" class="gs-copy-icon" x-show="copied" x-cloak />
            </button>
        </div>
    @endforeach

    @if ($qris)
        <div class="gs-bank-qris">
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($qris) }}" alt="QRIS gs.POS" loading="lazy">
            <span>Atau scan QRIS ini dari aplikasi bank / e-wallet</span>
        </div>
    @endif

    @if ($accounts === [] && ! $qris)
        <p class="gs-bank-empty">Rekening belum diatur tim gs.POS. Hubungi kami untuk instruksi pembayaran.</p>
    @endif

    <ul class="gs-bank-steps">
        @if ($invoice)
            <li>Transfer tepat <strong>{{ \App\Support\Money::format((string) $invoice->amount) }}</strong> dan tulis <strong class="font-mono">{{ $invoice->number }}</strong> di berita transfer.</li>
        @endif
        <li>Unggah bukti transfer di bawah, lalu klik Lanjutkan.</li>
        @if ($note)
            <li class="whitespace-pre-line">{{ $note }}</li>
        @endif
    </ul>
</div>
