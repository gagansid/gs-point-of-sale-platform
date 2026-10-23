<x-self-order.layout :context="$context" title="Pesan dari meja">
    @if ($available !== null)
        <main class="so-main">
            <section class="so-card">
                <div class="so-empty">
                    <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-clock')</span>
                    <p class="so-empty-title">Belum bisa memesan dari {{ $context->table->name }}</p>
                    <p>{{ $available }}</p>
                </div>
            </section>
        </main>
    @else
        <livewire:self-order.order-page />
    @endif
</x-self-order.layout>
