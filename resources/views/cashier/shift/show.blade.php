{{-- /cashier/shift: buka shift (carry-over kas awal) atau X-report + kas masuk/keluar + tutup shift (Q54, ADR 0017). Gaya Q131. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Shift" active="shift">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <h1>{{ $shift === null ? 'Buka shift' : 'Shift berjalan' }}</h1>
                <p>{{ $shift === null ? 'Belum ada shift terbuka di browser ini. Hitung uang di laci untuk memulai.' : 'Pantau X-report, catat kas masuk/keluar, lalu tutup shift saat selesai bertugas.' }}</p>
            </div>
        </header>

        @if ($shift === null)
            <section class="pos-card pos-narrow">
                @can('shift.operate')
                    @if ($carryOver !== null)
                        <div class="pos-card-body pos-form">
                            <div class="pos-open-carry">
                                <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-banknotes')</span>
                                <div>
                                    <small>Kas awal</small>
                                    <strong>{{ $money($carryOver) }}</strong>
                                </div>
                            </div>
                            <p class="pos-hint">Kas awal diambil dari sisa laci shift sebelumnya di perangkat ini. Tidak perlu menghitung ulang.</p>
                            <form method="POST" action="{{ route('cashier.shift.store') }}" novalidate>
                                @csrf
                                <button type="submit" class="site-btn site-btn-primary pos-btn-block">@svg('heroicon-o-play', ['aria-hidden' => 'true']) Buka shift</button>
                            </form>
                        </div>

                        {{-- Koreksi: angka lain dari carry-over butuh alasan + izin/PIN atasan --}}
                        <details class="pos-disclosure" @if ($errors->hasAny(['opening_cash', 'opening_cash_note', 'approver_user_id', 'approver_pin'])) open @endif>
                            <summary>@svg('heroicon-o-pencil-square', ['aria-hidden' => 'true']) Uang di laci berbeda?</summary>
                            <form method="POST" action="{{ route('cashier.shift.store') }}" class="pos-card-body pos-form" novalidate>
                                @csrf
                                <div class="pos-field">
                                    <label for="opening_cash">Kas awal sebenarnya<span class="site-required" aria-hidden="true">*</span></label>
                                    <x-cashier.money name="opening_cash" id="opening_cash" :value="old('opening_cash')" :invalid="$errors->has('opening_cash')" describedby="opening-error" />
                                    @error('opening_cash') <small id="opening-error" class="pos-field-error">{{ $message }}</small> @enderror
                                </div>
                                <label class="pos-field" for="opening_cash_note"><span>Alasan<span class="site-required" aria-hidden="true">*</span></span>
                                    <input id="opening_cash_note" name="opening_cash_note" type="text" maxlength="255" value="{{ old('opening_cash_note') }}" placeholder="mis. ada uang kecil dari brankas" required>
                                    @error('opening_cash_note') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </label>
                                @include('cashier.partials.approver', ['approvers' => $openingApprovers, 'id' => 'open'])
                                <button type="submit" class="site-btn site-btn-outline">Buka dengan kas awal ini</button>
                            </form>
                        </details>
                    @else
                        <form method="POST" action="{{ route('cashier.shift.store') }}" class="pos-card-body pos-form" novalidate>
                            @csrf
                            <div class="pos-field">
                                <label for="opening_cash">Kas awal di laci<span class="site-required" aria-hidden="true">*</span></label>
                                <x-cashier.money name="opening_cash" id="opening_cash" size="large" :value="old('opening_cash')" :autofocus="true"
                                    :invalid="$errors->has('opening_cash')" describedby="opening-error" />
                                @error('opening_cash') <small id="opening-error" class="pos-field-error">{{ $message }}</small> @enderror
                            </div>
                            <button type="submit" class="site-btn site-btn-primary pos-btn-block">@svg('heroicon-o-play', ['aria-hidden' => 'true']) Buka shift</button>
                        </form>
                    @endif
                @else
                    <div class="pos-empty">
                        <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-lock-closed')</span>
                        <strong>Tidak ada izin</strong>
                        <p>Anda tidak punya izin membuka shift. Minta supervisor atau owner membukanya.</p>
                    </div>
                @endcan
            </section>
        @else
            <div class="pos-detail">
                @include('cashier.partials.recap', ['recap' => $recap, 'timezone' => $timezone])

                <div class="pos-detail-side">
                    @can('shift.operate')
                        {{-- Kas masuk/keluar (ADR 0017): masuk ke kas seharusnya, tidak bisa diubah setelah dicatat --}}
                        <section class="pos-card">
                            <div class="pos-card-head"><h2>Kas masuk/keluar</h2></div>
                            <form method="POST" action="{{ route('cashier.shift.cash-movements.store') }}" class="pos-card-body pos-form" novalidate>
                                @csrf
                                <p class="pos-hint">Catat uang laci yang dipakai atau ditambah di tengah shift, agar selisih saat tutup bisa dijelaskan.</p>
                                <input type="hidden" name="id" value="{{ $movementId }}">
                                <div class="cashier-tabs" role="radiogroup" aria-label="Jenis kas">
                                    <label><input type="radio" name="type" value="out" @checked(old('type', 'out') === 'out')><span>Kas keluar</span></label>
                                    <label><input type="radio" name="type" value="in" @checked(old('type') === 'in')><span>Kas masuk</span></label>
                                </div>
                                <div class="pos-field-row">
                                    <label class="pos-field" for="reason"><span>Alasan<span class="site-required" aria-hidden="true">*</span></span>
                                        <select id="reason" name="reason" class="pos-select" required>
                                            @foreach (\App\Enums\CashMovementReason::manual() as $reason)
                                                <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)
                                                    data-types="{{ collect(\App\Enums\CashMovementType::cases())->filter(fn ($t) => $reason->allows($t))->map->value->implode(' ') }}">{{ $reason->getLabel() }}</option>
                                            @endforeach
                                        </select>
                                        @error('reason') <small class="pos-field-error">{{ $message }}</small> @enderror
                                    </label>
                                    <div class="pos-field">
                                        <label for="amount">Nominal<span class="site-required" aria-hidden="true">*</span></label>
                                        <x-cashier.money name="amount" id="amount" :value="old('amount')" :invalid="$errors->has('amount')" describedby="amount-error" />
                                        @error('amount') <small id="amount-error" class="pos-field-error">{{ $message }}</small> @enderror
                                    </div>
                                </div>
                                <label class="pos-field" for="note"><span>Catatan</span>
                                    <input id="note" name="note" type="text" maxlength="255" value="{{ old('note') }}" placeholder="mis. beli galon 2">
                                    @error('note') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </label>
                                @if ($cashOutApprovers->isNotEmpty() && $outlet->cash_out_approval_limit !== null)
                                    <p class="pos-hint">Kas keluar di atas {{ $money($outlet->cash_out_approval_limit) }} butuh PIN atasan.</p>
                                    @include('cashier.partials.approver', ['approvers' => $cashOutApprovers, 'id' => 'cash', 'required' => false])
                                @endif
                                <button type="submit" class="site-btn site-btn-outline">@svg('heroicon-o-arrows-right-left', ['aria-hidden' => 'true']) Catat kas</button>
                            </form>
                        </section>

                        <section class="pos-card">
                            <div class="pos-card-head"><h2>Tutup shift</h2></div>
                            <form method="POST" action="{{ route('cashier.shift.close') }}" class="pos-card-body pos-form" novalidate
                                data-close-form data-expected="{{ $recap['cash']['expected_cash'] }}" data-float="{{ $outlet->cash_float }}"
                                data-confirm-title="Tutup shift?" data-confirm-ok="Tutup shift" data-confirm-icon="warning" data-confirm-tone="danger"
                                data-confirm-text="Kas dihitung Rp{amount}. Z-report dibuat dan transaksi berikutnya butuh shift baru.">
                                @csrf
                                <p class="pos-hint">Siapa pun yang sedang bertugas boleh menutup. Hitung uang di laci; selisih dengan kas seharusnya tercatat di laporan.</p>
                                <div class="pos-field">
                                    <label for="actual_cash">Kas dihitung<span class="site-required" aria-hidden="true">*</span></label>
                                    <x-cashier.money name="actual_cash" id="actual_cash" size="large" :value="old('actual_cash')" :invalid="$errors->has('actual_cash')" describedby="actual-error" />
                                    @error('actual_cash') <small id="actual-error" class="pos-field-error">{{ $message }}</small> @enderror
                                </div>
                                {{-- Selisih langsung terlihat sebelum menutup (angka final tetap dihitung server) --}}
                                <div class="pos-diff" data-difference aria-live="polite" hidden>
                                    <span>Kas seharusnya {{ $money($recap['cash']['expected_cash']) }}</span>
                                    <strong></strong>
                                </div>
                                <div class="pos-field">
                                    <label for="cash_deposited">Disetor</label>
                                    <x-cashier.money name="cash_deposited" id="cash_deposited" :value="old('cash_deposited')" :invalid="$errors->has('cash_deposited')" describedby="deposit-error" />
                                    @error('cash_deposited') <small id="deposit-error" class="pos-field-error">{{ $message }}</small> @enderror
                                    <small class="pos-field-hint" data-float-left aria-live="polite">
                                        {{ $outlet->cash_float !== null ? 'Terisi otomatis: kas dihitung − modal laci '.$money($outlet->cash_float).'.' : 'Uang yang diambil dari laci. Kosong = semua ditinggal di laci.' }}
                                    </small>
                                </div>
                                <label class="pos-field" for="close_note"><span>Catatan</span>
                                    <input id="close_note" name="note" type="text" maxlength="255" value="{{ old('note') }}" placeholder="Opsional">
                                    @error('note') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </label>
                                <button type="submit" class="site-btn site-btn-primary">@svg('heroicon-o-lock-closed', ['aria-hidden' => 'true']) Tutup shift</button>
                            </form>
                        </section>

                        {{-- Koreksi kas awal shift berjalan: alasan + izin/PIN atasan --}}
                        <details class="pos-card pos-disclosure" @if ($errors->hasAny(['opening_cash', 'opening_cash_note'])) open @endif>
                            <summary>@svg('heroicon-o-pencil-square', ['aria-hidden' => 'true']) Koreksi kas awal</summary>
                            <form method="POST" action="{{ route('cashier.shift.opening-cash') }}" class="pos-card-body pos-form" novalidate>
                                @csrf
                                <div class="pos-field">
                                    <label for="fix_opening_cash">Kas awal sebenarnya<span class="site-required" aria-hidden="true">*</span></label>
                                    <x-cashier.money name="opening_cash" id="fix_opening_cash" :value="old('opening_cash')" :invalid="$errors->has('opening_cash')" />
                                    @error('opening_cash') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </div>
                                <label class="pos-field" for="fix_opening_note"><span>Alasan<span class="site-required" aria-hidden="true">*</span></span>
                                    <input id="fix_opening_note" name="opening_cash_note" type="text" maxlength="255" value="{{ old('opening_cash_note') }}" required>
                                    @error('opening_cash_note') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </label>
                                @include('cashier.partials.approver', ['approvers' => $openingApprovers, 'id' => 'fix'])
                                <button type="submit" class="site-btn site-btn-outline">Simpan koreksi</button>
                            </form>
                        </details>

                        <script>
                            // Setelah skrip layout (data-money, posMoneySet) siap
                            document.addEventListener('DOMContentLoaded', () => {
                                // Alasan yang hanya untuk kas keluar disembunyikan saat "Kas masuk" dipilih
                                const reason = document.querySelector('#reason');
                                const syncReasons = () => {
                                    const type = document.querySelector('input[name=type]:checked')?.value ?? 'out';
                                    [...reason.options].forEach((o) => { o.hidden = ! o.dataset.types.split(' ').includes(type); });
                                    if (reason.selectedOptions[0]?.hidden) reason.value = [...reason.options].find((o) => ! o.hidden).value;
                                };
                                document.querySelectorAll('input[name=type]').forEach((r) => r.addEventListener('change', syncReasons));
                                syncReasons();

                                // Selisih (hijau pas / kuning lebih / merah kurang) & sisa laci
                                const form = document.querySelector('[data-close-form]');
                                const actual = form.querySelector('input[name=actual_cash]');
                                const deposit = form.querySelector('input[name=cash_deposited]');
                                const depositBox = deposit.closest('[data-money]');
                                const diffBox = form.querySelector('[data-difference]');
                                const floatOut = form.querySelector('[data-float-left]');
                                const cashFloat = form.dataset.float === '' ? null : Number(form.dataset.float);
                                const rp = (value) => 'Rp' + window.posMoneyFormat.fromRaw(String(Math.round(Math.abs(value) * 100) / 100));
                                let depositTouched = deposit.value !== '';

                                const update = () => {
                                    if (actual.value === '') { diffBox.hidden = true; return; }
                                    const counted = Number(actual.value);
                                    const diff = Math.round((counted - Number(form.dataset.expected)) * 100) / 100;
                                    diffBox.hidden = false;
                                    diffBox.className = 'pos-diff ' + (diff === 0 ? 'is-ok' : diff > 0 ? 'is-over' : 'is-short');
                                    diffBox.querySelector('strong').textContent = diff === 0 ? 'Pas, tanpa selisih' : `${diff > 0 ? 'Lebih' : 'Kurang'} ${rp(diff)}`;
                                    if (! depositTouched && cashFloat !== null) window.posMoneySet(depositBox, String(Math.max(0, counted - cashFloat)));
                                    const left = counted - Number(deposit.value || 0);
                                    floatOut.textContent = left < 0 ? 'Disetor melebihi kas dihitung' : `Ditinggal di laci ${rp(left)} (kas awal shift berikutnya)`;
                                };
                                form.addEventListener('money:change', (event) => {
                                    if (event.target === depositBox && event.detail.typed) depositTouched = true;
                                    if (event.target !== depositBox || event.detail.typed) update();
                                });
                                update();
                            });
                        </script>
                    @else
                        <section class="pos-card">
                            <div class="pos-empty">
                                <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-lock-closed')</span>
                                <strong>Tidak ada izin</strong>
                                <p>Anda tidak punya izin mengelola shift.</p>
                            </div>
                        </section>
                    @endcan
                </div>
            </div>
        @endif
    </div>
</x-cashier.layout>
