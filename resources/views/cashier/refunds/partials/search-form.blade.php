{{-- Form cari nomor transaksi untuk retur (ADR 0018). --}}
<form method="GET" action="{{ route('cashier.refunds.search') }}" class="pos-search-row" novalidate>
    <label class="pos-field" for="refund-number"><span>Nomor transaksi</span>
        <span class="pos-search-field">
            @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
            <input id="refund-number" name="number" type="text" maxlength="40" value="{{ old('number', request('number')) }}" placeholder="mis. JKT01-261014-0001" autocomplete="off"
                @error('number') aria-invalid="true" aria-describedby="refund-number-error" @enderror>
        </span>
    </label>
    <button type="submit" class="site-btn site-btn-primary">Cari</button>
</form>
@error('number') <p id="refund-number-error" class="pos-field-error">{{ $message }}</p> @enderror
