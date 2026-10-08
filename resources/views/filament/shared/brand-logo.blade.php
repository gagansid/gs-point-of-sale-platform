{{--
    Logo gs.POS: mark + wordmark (docs/standards/ui/filament-theme.md §4).
    Mark tanpa kotak latar seperti gs-task-tracker; warna garis diatur CSS (.bm-frame/.bm-accent):
    navy + abu di latar terang, putih + mist di sidebar navy & dark mode.
--}}
@props(['tag' => null])

<span class="brand-logo">
    <svg class="brand-mark" viewBox="10 12 44 44" aria-hidden="true">
        <rect class="bm-frame" x="14" y="16" width="36" height="26" rx="4" fill="none" stroke-width="4.5" />
        <path class="bm-accent" d="M22 50h20" stroke-width="5" stroke-linecap="round" />
        <path class="bm-accent" d="M22 29h12" stroke-width="4.5" stroke-linecap="round" />
    </svg>
    <span class="brand-word"><span class="w-gs">gs</span>.POS</span>
    @if ($tag)
        <span class="brand-tag">{{ $tag }}</span>
    @endif
</span>
