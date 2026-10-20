{{--
    Logo bank (Q124): gambar unggahan admin bila ada, selain itu logo vektor (SVG inline) dari singkatan & warna merek.
    Pemakaian: <x-bank-logo :bank="$bank" /> atau <x-bank-logo name="BCA" /> (tanpa master: warna primary).
--}}
@props(['bank' => null, 'name' => null, 'size' => 40])
@php
    /** @var \App\Models\Bank|null $bank */
    $label = $bank?->short_name ?? \Illuminate\Support\Str::limit((string) $name, 8, '');
    $bg = $bank?->color ?? '#2d4282';
    $fg = $bank?->text_color ?? '#ffffff';
    $len = mb_strlen($label);
    // Ukuran huruf menyesuaikan panjang singkatan agar tetap muat di kotak
    $font = match (true) { $len <= 3 => 15, $len <= 4 => 13, $len <= 5 => 11, $len <= 7 => 9, default => 8 };
@endphp
@if ($bank?->logo_path)
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($bank->logo_path) }}" alt="{{ $bank->name }}"
        {{ $attributes->class('gs-bank-logo-img') }} style="width: {{ $size }}px; height: {{ $size }}px" loading="lazy">
@else
    <svg {{ $attributes->class('gs-bank-logo-svg') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 40 40" role="img" aria-label="{{ $bank?->name ?? $label }}" xmlns="http://www.w3.org/2000/svg">
        {{-- Tanpa <defs>/id: logo bisa dirender berkali-kali di satu halaman (dropdown) tanpa bentrok --}}
        <rect width="40" height="40" rx="9" fill="{{ $bg }}" />
        <path d="M0 9 C0 4 4 0 9 0 L31 0 C36 0 40 4 40 9 L40 14 C 28 8, 12 18, 0 12 Z" fill="#fff" fill-opacity=".1" />
        <rect x="0.5" y="0.5" width="39" height="39" rx="8.5" fill="none" stroke="#000" stroke-opacity=".06" />
        <path d="M0 29 C 12 24, 26 34, 40 27 L40 31 C 40 36 36 40 31 40 L9 40 C 4 40 0 36 0 31 Z" fill="#fff" fill-opacity=".12" />
        <text x="20" y="{{ 20 + $font * 0.36 }}" text-anchor="middle" font-family="Plus Jakarta Sans, Inter, Arial, sans-serif" font-size="{{ $font }}" font-weight="800"
            letter-spacing="{{ $len <= 4 ? '0.3' : '0' }}" fill="{{ $fg }}">{{ $label }}</text>
    </svg>
@endif
