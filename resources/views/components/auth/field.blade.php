{{--
    Field halaman masuk: label, ikon di kiri, 44px; type="password" mendapat tombol mata tanpa border di kanan.
    Nilai lama (old) diisi kecuali kata sandi.
--}}
@props(['name', 'label', 'type' => 'text', 'icon' => null, 'value' => null, 'error' => null, 'hint' => null])
@php
    $id = $attributes->get('id', $name);
    $message = $error ?? ($errors->first($name) ?: null);
@endphp
<div {{ $attributes->only(['data-mode'])->merge(['class' => 'cashier-field']) }}>
    <label for="{{ $id }}">{{ $label }}</label>
    <div @class(['auth-input', 'has-icon' => $icon, 'has-reveal' => $type === 'password'])>
        @if ($icon)
            <x-site.icon :name="$icon" class="auth-input-icon" />
        @endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
            value="{{ $type === 'password' ? '' : old($name, $value) }}"
            @if ($message) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except(['id', 'data-mode']) }}>
        @if ($type === 'password')
            <button type="button" class="auth-reveal" data-auth-reveal="{{ $id }}" aria-label="Tampilkan kata sandi" aria-pressed="false">
                <x-site.icon name="eye" class="auth-reveal-show" />
                <x-site.icon name="eye-slash" class="auth-reveal-hide" />
            </button>
        @endif
    </div>
    @if ($hint) <p class="auth-field-hint">{{ $hint }}</p> @endif
    @if ($message) <p id="{{ $id }}-error" class="cashier-field-error">{{ $message }}</p> @endif
</div>
