{{-- Field input form publik dengan label, nilai lama, dan pesan error. --}}
@props(['name', 'label', 'type' => 'text', 'required' => false])

<div class="site-field">
    <label for="{{ $name }}">{{ $label }}@if ($required)<span class="site-required" aria-hidden="true">*</span>@endif</label>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name) }}"
        @if ($required) required @endif
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->merge(['maxlength' => 150]) }}
    >
    @error($name) <p id="{{ $name }}-error" class="site-error">{{ $message }}</p> @enderror
</div>
