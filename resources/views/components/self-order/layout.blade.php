{{--
    Kerangka halaman pesan dari meja (ADR 0014, tema bisnis ADR 0027 §6): header 56px menempel (logo, bisnis, outlet,
    chip meja, Masuk / chip member). Navigasi bawah Menu · Keranjang · Pesananku ada di komponen Livewire.
--}}
@props(['context', 'title' => 'Pesan'])
@php($member = $context->member())
<!DOCTYPE html>
<html lang="id">
<head>
    <x-so.head :tenant="$context->tenant" :title="$title" />
</head>
<body class="so-body">
    <header class="so-header">
        <div class="so-header-inner">
            <x-so.brand :tenant="$context->tenant" :sub="$context->outlet->name" />
            <div class="so-header-actions">
                <span class="so-chip">@svg('heroicon-s-map-pin', ['aria-hidden' => 'true']) {{ $context->table->name }}</span>
                @if ($member)
                    <a class="so-member-chip" href="{{ route('member.home', ['business' => $context->tenant->slug]) }}" title="{{ $member->name }} · {{ number_format($member->points_balance, 0, ',', '.') }} poin">
                        <span class="so-member-chip-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($member->name), 0, 1)) }}</span>
                        <span class="so-member-chip-text so-num">{{ number_format($member->points_balance, 0, ',', '.') }} poin</span>
                    </a>
                @else
                    <a class="so-btn is-outline" href="{{ route('self-order.member', ['token' => $context->table->qr_token]) }}">Masuk</a>
                @endif
            </div>
        </div>
    </header>

    {{ $slot }}
</body>
</html>
