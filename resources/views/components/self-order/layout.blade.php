{{--
    Kerangka halaman pesan dari meja (ADR 0014, tema bisnis ADR 0027 §6): header 56px menempel (logo, bisnis, outlet,
    chip meja, Masuk / menu akun & Tentang aplikasi). Navigasi bawah Menu · Keranjang · Pesananku ada di komponen Livewire.
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
                @if ($context->loginRequired())
                    <x-so.account :tenant="$context->tenant" :member="$member"
                        :login-url="request()->routeIs('self-order.login', 'self-order.register') ? null : route('self-order.login', ['token' => $context->table->qr_token])"
                        :register-url="route('self-order.register', ['token' => $context->table->qr_token])"
                        :logout-url="route('self-order.logout', ['token' => $context->table->qr_token])" />
                @else
                    <x-so.account :tenant="$context->tenant" />
                @endif
            </div>
        </div>
    </header>

    {{ $slot }}

    <x-so.about :tenant="$context->tenant" />
</body>
</html>
