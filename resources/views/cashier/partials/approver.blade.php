{{-- Pilihan approver + PIN (aksi 🔑). $approvers kosong = pelaku sudah berizin; $id = awalan id field unik per form. --}}
@if ($approvers->isNotEmpty())
    <div class="site-field">
        <label for="{{ $id }}-approver">Approver @if ($required ?? true)<span class="site-required" aria-hidden="true">*</span>@endif</label>
        <select id="{{ $id }}-approver" name="approver_user_id" class="cashier-select">
            <option value="">Pilih approver…</option>
            @foreach ($approvers as $approver)
                <option value="{{ $approver->id }}" @selected(old('approver_user_id') === $approver->id)>{{ $approver->name }} ({{ $approver->role->getLabel() }})</option>
            @endforeach
        </select>
        @error('approver_user_id') <p class="site-error">{{ $message }}</p> @enderror
    </div>
    <div class="site-field">
        <label for="{{ $id }}-pin">PIN approver @if ($required ?? true)<span class="site-required" aria-hidden="true">*</span>@endif</label>
        <input id="{{ $id }}-pin" name="approver_pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off">
        @error('approver_pin') <p class="site-error">{{ $message }}</p> @enderror
    </div>
@endif
