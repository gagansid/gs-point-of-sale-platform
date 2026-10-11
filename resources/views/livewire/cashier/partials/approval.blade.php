{{-- Approval PIN (diskon di atas batas): approver memasukkan PIN-nya sendiri di layar ini. --}}
<div class="pos-approval" role="group" aria-label="Approval">
    <p class="pos-approval-title">Perlu approval</p>
    <select wire:model="approverId" aria-label="Approver">
        <option value="">Pilih approver…</option>
        @foreach ($this->approvers as $approver)
            <option value="{{ $approver->id }}">{{ $approver->name }} ({{ $approver->role->getLabel() }})</option>
        @endforeach
    </select>
    <input type="password" wire:model="approverPin" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="PIN approver (6 digit)" aria-label="PIN approver">
</div>
