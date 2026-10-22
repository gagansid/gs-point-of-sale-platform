{{-- Pilihan approver + PIN (aksi 🔑). $approvers kosong = pelaku sudah berizin; $id = awalan id field unik per form. --}}
@if ($approvers->isNotEmpty())
    <div class="pos-approver">
        <span class="pos-approver-title">@svg('heroicon-o-key', ['aria-hidden' => 'true']) Perlu persetujuan</span>
        <div class="pos-field-row">
            <label class="pos-field" for="{{ $id }}-approver"><span>Approver @if ($required ?? true)<span class="site-required" aria-hidden="true">*</span>@endif</span>
                <select id="{{ $id }}-approver" name="approver_user_id" class="pos-select">
                    <option value="">Pilih approver…</option>
                    @foreach ($approvers as $approver)
                        <option value="{{ $approver->id }}" @selected(old('approver_user_id') === $approver->id)>{{ $approver->name }} ({{ $approver->role->getLabel() }})</option>
                    @endforeach
                </select>
                @error('approver_user_id') <small class="pos-field-error">{{ $message }}</small> @enderror
            </label>
            <label class="pos-field" for="{{ $id }}-pin"><span>PIN approver @if ($required ?? true)<span class="site-required" aria-hidden="true">*</span>@endif</span>
                <input id="{{ $id }}-pin" name="approver_pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="6 digit">
                @error('approver_pin') <small class="pos-field-error">{{ $message }}</small> @enderror
            </label>
        </div>
    </div>
@endif
