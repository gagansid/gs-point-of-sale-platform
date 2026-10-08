{{-- Logo gs.POS: mark + wordmark (docs/standards/ui/filament-theme.md §4) --}}
@props(['tag' => null])

<span class="brand-logo">
    <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true">
        <rect width="64" height="64" rx="14" fill="#1B2A55" />
        <rect x="14" y="16" width="36" height="26" rx="4" fill="none" stroke="#FFFFFF" stroke-width="4.5" />
        <path d="M22 50h20" stroke="#B8C0D4" stroke-width="5" stroke-linecap="round" />
        <path d="M22 29h12" stroke="#B8C0D4" stroke-width="4.5" stroke-linecap="round" />
    </svg>
    <span class="brand-word"><span class="w-gs">gs</span>.POS</span>
    @if ($tag)
        <span class="brand-tag">{{ $tag }}</span>
    @endif
</span>
