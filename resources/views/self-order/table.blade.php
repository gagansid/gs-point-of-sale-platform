<x-self-order.layout :context="$context" title="Pesan dari meja">
    @if ($available !== null)
        <section class="member-card">
            <h1 class="member-title">{{ $context->table->name }}</h1>
            <p class="member-muted">{{ $available }}</p>
        </section>
    @else
        <livewire:self-order.order-page />
    @endif
</x-self-order.layout>
