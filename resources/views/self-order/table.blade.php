<x-self-order.layout :context="$context" title="Pesan dari meja">
    @if ($available !== null)
        <section class="member-card">
            <h1 class="member-title">{{ $context->table->name }}</h1>
            <p class="member-muted">{{ $available }}</p>
        </section>
    @else
        <section class="member-card">
            <h1 class="member-title">{{ $context->table->name }}</h1>
            <p class="member-muted">{{ $context->setting->welcome_text ?? 'Menu segera tersedia.' }}</p>
        </section>
    @endif
</x-self-order.layout>
