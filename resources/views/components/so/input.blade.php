{{--
    Field halaman pelanggan (form.md): label 12.5px, field 32px berikon di kiri, bantuan & error 12px di bawahnya.
    type="password" mendapat tombol tampilkan/sembunyikan (data-so-reveal, skrip di x-so.head).
--}}
@props([
    'name',
    'label',
    'icon',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'optional' => false,
])
@php
    $id = 'f-'.$name;
    $error = $errors->first($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="so-field">
    <label class="so-label" for="{{ $id }}">{{ $label }}@if ($optional) <small>(opsional)</small>@endif</label>
    <div @class(['so-input-icon', 'has-reveal' => $type === 'password'])>
        @svg($icon, ['aria-hidden' => 'true'])
        <input {{ $attributes->class(['so-input']) }} id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
            @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        @if ($type === 'password')
            <button type="button" class="so-reveal" data-so-reveal="{{ $id }}" aria-label="Tampilkan kata sandi" aria-pressed="false">
                <span data-show>@svg('heroicon-o-eye', ['aria-hidden' => 'true'])</span>
                <span data-hide hidden>@svg('heroicon-o-eye-slash', ['aria-hidden' => 'true'])</span>
            </button>
        @endif
    </div>
    @if ($help)
        <small class="so-help" id="{{ $id }}-help">{{ $help }}</small>
    @endif
    @if ($error)
        <em class="so-error" id="{{ $id }}-error" role="alert">{{ $error }}</em>
    @endif
</div>
