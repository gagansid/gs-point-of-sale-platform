{{-- /cashier/shift: buka shift (carry-over kas awal) atau X-report + kas masuk/keluar + tutup shift (Q54, ADR 0017). --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Shift" active="shift">
    @if ($shift === null)
        <section class="cashier-card cashier-narrow">
            <h1>Buka shift</h1>

            @can('shift.operate')
                @if ($carryOver !== null)
                    <p class="cashier-muted">Kas awal diambil dari sisa laci shift sebelumnya di perangkat ini. Tidak perlu menghitung ulang.</p>
                    <dl class="cashier-dl cashier-carry">
                        <dt>Kas awal</dt><dd><strong>{{ $money($carryOver) }}</strong></dd>
                    </dl>
                    <form method="POST" action="{{ route('cashier.shift.store') }}" class="site-form" novalidate>
                        @csrf
                        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Buka shift</button>
                    </form>

                    {{-- Koreksi: angka lain dari carry-over butuh alasan + izin/PIN atasan --}}
                    <details class="cashier-details" @if ($errors->hasAny(['opening_cash', 'opening_cash_note', 'approver_user_id', 'approver_pin'])) open @endif>
                        <summary>Uang di laci berbeda?</summary>
                        <form method="POST" action="{{ route('cashier.shift.store') }}" class="site-form" novalidate>
                            @csrf
                            <div class="site-field">
                                <label for="opening_cash">Kas awal sebenarnya (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="opening_cash" name="opening_cash" type="number" min="0" step="any" inputmode="decimal"
                                    value="{{ old('opening_cash') }}" required
                                    @error('opening_cash') aria-invalid="true" aria-describedby="opening-error" @enderror>
                                @error('opening_cash') <p id="opening-error" class="site-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="site-field">
                                <label for="opening_cash_note">Alasan<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="opening_cash_note" name="opening_cash_note" type="text" maxlength="255" value="{{ old('opening_cash_note') }}" required>
                                @error('opening_cash_note') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            @include('cashier.partials.approver', ['approvers' => $openingApprovers, 'id' => 'open'])
                            <button type="submit" class="site-btn site-btn-outline site-btn-block">Buka dengan kas awal ini</button>
                        </form>
                    </details>
                @else
                    <p class="cashier-muted">Belum ada shift terbuka di browser ini. Hitung uang di laci, lalu isi kas awal.</p>
                    <form method="POST" action="{{ route('cashier.shift.store') }}" class="site-form" novalidate>
                        @csrf
                        <div class="site-field">
                            <label for="opening_cash">Kas awal (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                            <input id="opening_cash" name="opening_cash" type="number" min="0" step="any" inputmode="decimal"
                                value="{{ old('opening_cash') }}" required autofocus
                                @error('opening_cash') aria-invalid="true" aria-describedby="opening-error" @enderror>
                            @error('opening_cash') <p id="opening-error" class="site-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Buka shift</button>
                    </form>
                @endif
            @else
                <div class="site-alert site-alert-warning" role="alert">Anda tidak punya izin membuka shift.</div>
            @endcan
        </section>
    @else
        <div class="cashier-grid-2">
            @include('cashier.partials.recap', ['recap' => $recap, 'timezone' => $timezone])

            <div>
                @can('shift.operate')
                    {{-- Kas masuk/keluar (ADR 0017): masuk ke kas seharusnya, tidak bisa diubah setelah dicatat --}}
                    <section class="cashier-card">
                        <h2>Kas masuk/keluar</h2>
                        <p class="cashier-muted">Catat uang laci yang dipakai atau ditambah di tengah shift, agar selisih saat tutup bisa dijelaskan.</p>
                        <form method="POST" action="{{ route('cashier.shift.cash-movements.store') }}" class="site-form" novalidate>
                            @csrf
                            <input type="hidden" name="id" value="{{ $movementId }}">
                            <div class="cashier-tabs" role="radiogroup" aria-label="Jenis kas">
                                <label><input type="radio" name="type" value="out" @checked(old('type', 'out') === 'out')><span>Kas keluar</span></label>
                                <label><input type="radio" name="type" value="in" @checked(old('type') === 'in')><span>Kas masuk</span></label>
                            </div>
                            <div class="site-field">
                                <label for="reason">Alasan<span class="site-required" aria-hidden="true">*</span></label>
                                <select id="reason" name="reason" class="cashier-select" required>
                                    @foreach (\App\Enums\CashMovementReason::manual() as $reason)
                                        <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)
                                            data-types="{{ collect(\App\Enums\CashMovementType::cases())->filter(fn ($t) => $reason->allows($t))->map->value->implode(' ') }}">{{ $reason->getLabel() }}</option>
                                    @endforeach
                                </select>
                                @error('reason') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="site-field">
                                <label for="amount">Nominal (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="amount" name="amount" type="number" min="1" step="any" inputmode="decimal" value="{{ old('amount') }}" required
                                    @error('amount') aria-invalid="true" aria-describedby="amount-error" @enderror>
                                @error('amount') <p id="amount-error" class="site-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="site-field">
                                <label for="note">Catatan</label>
                                <input id="note" name="note" type="text" maxlength="255" value="{{ old('note') }}" placeholder="Beli galon 2">
                                @error('note') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            @if ($cashOutApprovers->isNotEmpty() && $outlet->cash_out_approval_limit !== null)
                                <p class="cashier-muted">Kas keluar di atas {{ $money($outlet->cash_out_approval_limit) }} butuh PIN atasan.</p>
                                @include('cashier.partials.approver', ['approvers' => $cashOutApprovers, 'id' => 'cash', 'required' => false])
                            @endif
                            <button type="submit" class="site-btn site-btn-outline site-btn-block">Catat</button>
                        </form>
                    </section>

                    <section class="cashier-card">
                        <h2>Tutup shift</h2>
                        <p class="cashier-muted">Siapa pun yang sedang bertugas boleh menutup. Hitung uang di laci; selisih dengan kas seharusnya tercatat di laporan.</p>

                        <form method="POST" action="{{ route('cashier.shift.close') }}" class="site-form" novalidate
                            data-close-form data-expected="{{ $recap['cash']['expected_cash'] }}" data-float="{{ $outlet->cash_float }}">
                            @csrf
                            <div class="site-field">
                                <label for="actual_cash">Kas dihitung (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="actual_cash" name="actual_cash" type="number" min="0" step="any" inputmode="decimal"
                                    value="{{ old('actual_cash') }}" required
                                    @error('actual_cash') aria-invalid="true" aria-describedby="actual-error" @enderror>
                                @error('actual_cash') <p id="actual-error" class="site-error">{{ $message }}</p> @enderror
                                <p class="cashier-muted" data-difference aria-live="polite"></p>
                            </div>
                            <div class="site-field">
                                <label for="cash_deposited">Disetor (Rp)</label>
                                <input id="cash_deposited" name="cash_deposited" type="number" min="0" step="any" inputmode="decimal"
                                    value="{{ old('cash_deposited') }}"
                                    @error('cash_deposited') aria-invalid="true" aria-describedby="deposit-error" @enderror>
                                @error('cash_deposited') <p id="deposit-error" class="site-error">{{ $message }}</p> @enderror
                                <p class="cashier-muted" data-float-left aria-live="polite">
                                    {{ $outlet->cash_float !== null ? 'Terisi otomatis: kas dihitung − modal laci '.$money($outlet->cash_float).'.' : 'Uang yang diambil dari laci. Kosong = semua ditinggal di laci.' }}
                                </p>
                            </div>
                            <div class="site-field">
                                <label for="close_note">Catatan</label>
                                <input id="close_note" name="note" type="text" maxlength="255" value="{{ old('note') }}">
                                @error('note') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block"
                                data-confirm-step="Klik lagi untuk menutup shift">Tutup shift</button>
                        </form>
                    </section>

                    {{-- Koreksi kas awal shift berjalan: alasan + izin/PIN atasan --}}
                    <details class="cashier-card cashier-details" @if ($errors->hasAny(['opening_cash', 'opening_cash_note'])) open @endif>
                        <summary>Koreksi kas awal</summary>
                        <form method="POST" action="{{ route('cashier.shift.opening-cash') }}" class="site-form" novalidate>
                            @csrf
                            <div class="site-field">
                                <label for="fix_opening_cash">Kas awal sebenarnya (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="fix_opening_cash" name="opening_cash" type="number" min="0" step="any" inputmode="decimal" value="{{ old('opening_cash') }}" required>
                                @error('opening_cash') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="site-field">
                                <label for="fix_opening_note">Alasan<span class="site-required" aria-hidden="true">*</span></label>
                                <input id="fix_opening_note" name="opening_cash_note" type="text" maxlength="255" value="{{ old('opening_cash_note') }}" required>
                                @error('opening_cash_note') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                            @include('cashier.partials.approver', ['approvers' => $openingApprovers, 'id' => 'fix'])
                            <button type="submit" class="site-btn site-btn-outline site-btn-block">Simpan koreksi</button>
                        </form>
                    </details>

                    <script>
                        (() => {
                            // Alasan yang hanya untuk kas keluar disembunyikan saat "Kas masuk" dipilih
                            const reason = document.querySelector('#reason');
                            const syncReasons = () => {
                                const type = document.querySelector('input[name=type]:checked')?.value ?? 'out';
                                [...reason.options].forEach((o) => { o.hidden = ! o.dataset.types.split(' ').includes(type); });
                                if (reason.selectedOptions[0]?.hidden) reason.value = [...reason.options].find((o) => ! o.hidden).value;
                            };
                            document.querySelectorAll('input[name=type]').forEach((r) => r.addEventListener('change', syncReasons));
                            syncReasons();

                            // Selisih & sisa laci langsung terlihat sebelum menutup (angka final tetap dihitung server)
                            const form = document.querySelector('[data-close-form]');
                            const actual = form.querySelector('#actual_cash');
                            const deposit = form.querySelector('#cash_deposited');
                            const diffOut = form.querySelector('[data-difference]');
                            const floatOut = form.querySelector('[data-float-left]');
                            const cashFloat = form.dataset.float === '' ? null : Number(form.dataset.float);
                            const fmt = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 });
                            let depositTouched = deposit.value !== '';
                            deposit.addEventListener('input', () => { depositTouched = true; update(); });

                            function update() {
                                if (actual.value === '') { diffOut.textContent = ''; return; }
                                const counted = Number(actual.value);
                                const diff = counted - Number(form.dataset.expected);
                                diffOut.textContent = diff === 0 ? 'Pas, tanpa selisih' : `Selisih ${diff > 0 ? '+' : '−'}${fmt.format(Math.abs(diff))}`;
                                if (! depositTouched && cashFloat !== null) deposit.value = Math.max(0, counted - cashFloat);
                                const left = counted - Number(deposit.value || 0);
                                floatOut.textContent = left < 0 ? 'Disetor melebihi kas dihitung' : `Ditinggal di laci ${fmt.format(left)} (kas awal shift berikutnya)`;
                            }
                            actual.addEventListener('input', update);
                            update();
                        })();
                    </script>
                @else
                    <div class="site-alert site-alert-warning" role="alert">Anda tidak punya izin mengelola shift.</div>
                @endcan
            </div>
        </div>
    @endif
</x-cashier.layout>
