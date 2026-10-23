{{-- Kasir web → Antrean (ADR 0027 K8): pesanan meja lunas dimajukan sampai Diantar. Polling 5 detik. Gaya Q131. --}}
<div class="pos-page" wire:poll.5s x-data="{ col: 'queued' }">
    <header class="pos-page-head">
        <div>
            <h1>Antrean pesanan</h1>
            <p>Pesanan meja yang sudah lunas. Majukan sampai diantar; ketuk Urungkan di notifikasi bila salah.</p>
        </div>
        @if ($this->areas !== [])
            <label class="pos-field pos-queue-filter">
                <span class="sr-only">Area meja</span>
                <select class="pos-select" wire:model.live="area" aria-label="Area meja">
                    <option value="">Semua area</option>
                    @foreach ($this->areas as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </header>

    {{-- Layar sempit: kolom menjadi tab --}}
    <nav class="pos-queue-tabs" role="tablist" aria-label="Tahap antrean">
        @foreach ($columns as $key => [$label, $list])
            <button type="button" role="tab" x-on:click="col = '{{ $key }}'" :class="col === '{{ $key }}' && 'is-active'" :aria-selected="col === '{{ $key }}'">
                {{ $label }} <span class="pos-count">{{ $list->count() }}</span>
            </button>
        @endforeach
    </nav>

    <div class="pos-board is-3">
        @foreach ($columns as $key => [$label, $list])
            <section class="pos-card pos-queue-col" :class="col === '{{ $key }}' && 'is-current'" aria-label="{{ $label }}">
                <div class="pos-card-head">
                    <h2>{{ $label }} <span @class(['pos-count', 'is-alert' => $key === 'queued' && $list->contains(fn ($o) => $o->fulfillment_status === \App\Enums\FulfillmentStatus::Queued)])>{{ $list->count() }}</span></h2>
                </div>

                @forelse ($list as $order)
                    @include('livewire.cashier.partials.fulfillment-ticket', ['order' => $order, 'customer' => $this->customers[$order->id] ?? null])
                @empty
                    <div class="pos-empty">
                        <span class="pos-empty-icon" aria-hidden="true">@svg($key === 'served' ? 'heroicon-o-check-badge' : ($key === 'ready' ? 'heroicon-o-bell-alert' : 'heroicon-o-inbox'))</span>
                        <strong>{{ match ($key) { 'queued' => 'Belum ada pesanan lunas', 'ready' => 'Belum ada yang siap', default => 'Belum ada yang diantar' } }}</strong>
                        <p>{{ match ($key) { 'queued' => 'Pesanan meja yang dibayar akan muncul di sini dan berbunyi.', 'ready' => 'Pesanan yang selesai disiapkan pindah ke sini.', default => 'Pesanan yang diantar dalam 1 jam terakhir.' } }}</p>
                    </div>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
