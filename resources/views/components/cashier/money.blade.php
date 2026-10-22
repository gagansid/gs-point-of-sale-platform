{{--
    Input Rupiah untuk form biasa kasir web (tanpa Livewire/Alpine, Q131): tampil "150.000", terkirim angka murni lewat
    hidden input bernama $name (data-money, JS di layout). $size: null | 'large' | 'compact'.
--}}
@props(['name', 'value' => null, 'id' => null, 'size' => null, 'label' => null, 'autofocus' => false, 'invalid' => false, 'describedby' => null])
<span {{ $attributes->class(['pos-money', 'is-large' => $size === 'large', 'is-compact' => $size === 'compact']) }} data-money>
    <span class="pos-money-prefix">Rp</span>
    <input type="text" inputmode="decimal" autocomplete="off" placeholder="0"
        @if ($id) id="{{ $id }}" @endif
        @if ($label) aria-label="{{ $label }}" @endif
        @if ($autofocus) autofocus @endif
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedby) aria-describedby="{{ $describedby }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-money-raw>
</span>
