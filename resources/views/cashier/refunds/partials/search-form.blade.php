{{-- Form cari nomor transaksi untuk retur (ADR 0018). --}}
<form method="GET" action="{{ route('cashier.refunds.search') }}" class="site-form cashier-inline-search" novalidate>
    <div class="site-field">
        <label for="refund-number">Nomor transaksi</label>
        <input id="refund-number" name="number" type="text" maxlength="40" value="{{ old('number', request('number')) }}" placeholder="JKT01-261014-0001" autocomplete="off"
            @error('number') aria-invalid="true" aria-describedby="refund-number-error" @enderror>
        @error('number') <p id="refund-number-error" class="site-error">{{ $message }}</p> @enderror
    </div>
    <button type="submit" class="site-btn site-btn-outline">Cari</button>
</form>
