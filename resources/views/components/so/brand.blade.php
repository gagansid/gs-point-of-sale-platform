{{-- Merek di header halaman pelanggan: logo bisnis (Q128) atau inisial dalam kotak --primary-lt. --}}
@props(['tenant', 'sub' => null])
@php($logo = \App\Support\TenantAppearance::logo($tenant))
<div class="so-brand">
    <span class="so-brand-logo" aria-hidden="true">
        @if ($logo)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}" alt="">
        @else
            {{ mb_strtoupper(mb_substr(trim($tenant->name), 0, 1)) }}
        @endif
    </span>
    <span class="so-brand-text">
        <span class="so-brand-name">{{ $tenant->name }}</span>
        @if ($sub)
            <span class="so-brand-sub">{{ $sub }}</span>
        @endif
    </span>
</div>
