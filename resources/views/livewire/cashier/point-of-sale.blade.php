{{-- Layar transaksi kasir web (Q54). Nominal tampilan dari server (QuoteOrder/CheckoutOrder). --}}
@php
    $money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
    $catalog = $this->catalog;
    $quote = $this->quote;
    $totals = $quote['totals'];
@endphp
<div class="pos">
    {{-- Katalog --}}
    <section class="pos-catalog" aria-label="Produk">
        <div class="pos-search">
            <input type="search" wire:model.live.debounce.250ms="search" wire:keydown.enter.prevent="scan"
                placeholder="Cari produk atau scan barcode…" aria-label="Cari produk atau scan barcode" autofocus autocomplete="off">
        </div>

        <nav class="pos-categories" aria-label="Kategori">
            <button type="button" wire:click="$set('category', null)" @class(['is-active' => $category === null])>Semua</button>
            @if ($catalog['products']->contains('is_favorite', true))
                <button type="button" wire:click="$set('category', 'favorites')" @class(['is-active' => $category === 'favorites'])>★ Favorit</button>
            @endif
            @foreach ($catalog['categories'] as $cat)
                <button type="button" wire:click="$set('category', '{{ $cat->id }}')" @class(['is-active' => $category === $cat->id])>{{ $cat->name }}</button>
            @endforeach
        </nav>

        <div class="pos-grid">
            @forelse ($this->visibleProducts as $product)
                <div @class(['pos-tile', 'is-unavailable' => ! $product->is_available]) wire:key="p-{{ $product->id }}">
                    <button type="button" class="pos-tile-main" wire:click="addProduct('{{ $product->id }}')" @disabled(! $product->is_available)>
                        @if ($image = $product->imageUrl())
                            <img src="{{ $image }}" alt="" class="pos-tile-image" loading="lazy" decoding="async" width="400" height="400">
                        @else
                            <span class="pos-tile-image pos-tile-image--empty" aria-hidden="true">{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}</span>
                        @endif
                        <span class="pos-tile-name">{{ $product->name }}</span>
                        <span class="pos-tile-price">{{ $money($product->price) }}</span>
                        @if (! $product->is_available)
                            <span class="pos-tile-flag">Habis</span>
                        @elseif ($product->track_stock)
                            <span @class(['pos-tile-stock', 'is-low' => $product->isLowStock()])>Stok {{ $product->stock_qty }}</span>
                        @else
                            {{-- Tempat baris info tetap dipesan agar semua tile sama tinggi --}}
                            <span class="pos-tile-meta" aria-hidden="true"></span>
                        @endif
                    </button>
                    @if ($canToggle)
                        <button type="button" class="pos-tile-toggle" wire:click="toggleAvailability('{{ $product->id }}')"
                            title="{{ $product->is_available ? 'Tandai habis' : 'Tandai tersedia' }}">
                            {{ $product->is_available ? 'Habis?' : 'Tersedia' }}
                        </button>
                    @endif
                </div>
            @empty
                <p class="cashier-muted">Tidak ada produk.</p>
            @endforelse
        </div>
    </section>

    {{-- Keranjang --}}
    <aside class="pos-cart" aria-label="Keranjang">
        <div class="pos-cart-head">
            @if ($held = $this->heldCarts())
                <div class="pos-held" aria-label="Transaksi ditahan">
                    @foreach ($held as $h)
                        {{-- Konfirmasi di halaman (bukan dialog browser yang bisa diblokir) --}}
                        <span class="pos-held-chip" wire:key="h-{{ $h['id'] }}" x-data="{ confirming: false }">
                            <template x-if="! confirming">
                                <span class="pos-held-chip-body">
                                    <button type="button" class="pos-held-resume" wire:click="resume('{{ $h['id'] }}')" title="Lanjutkan {{ $h['label'] }}">
                                        ⏸ {{ $h['label'] }} <small>{{ $h['items'] }} item{{ $h['total'] ? ' · '.$money($h['total']) : '' }}</small>
                                    </button>
                                    <button type="button" class="pos-held-x" x-on:click="confirming = true" aria-label="Buang {{ $h['label'] }}" title="Buang">✕</button>
                                </span>
                            </template>
                            <template x-if="confirming">
                                <span class="pos-held-chip-body" role="alertdialog" aria-label="Buang {{ $h['label'] }}?">
                                    <span>Buang {{ $h['label'] }}?</span>
                                    <button type="button" class="pos-held-danger" wire:click="discardHeld('{{ $h['id'] }}')">Buang</button>
                                    <button type="button" class="pos-held-cancel" x-on:click="confirming = false">Batal</button>
                                </span>
                            </template>
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="pos-bill-bar">
                @if ($billId)
                    <span class="pos-bill-tag">Open bill {{ $tableLabel ?: '' }}</span>
                @endif
                <button type="button" class="site-btn site-btn-outline" wire:click="$set('showBills', true)">
                    Open bill ({{ $this->openBills->count() }})
                </button>
            </div>
            <div class="pos-segment" role="group" aria-label="Tipe order">
                <button type="button" wire:click="$set('orderType', 'takeaway')" @class(['is-active' => $orderType === 'takeaway'])>Bawa pulang</button>
                <button type="button" wire:click="$set('orderType', 'dine_in')" @class(['is-active' => $orderType === 'dine_in'])>Makan di tempat</button>
            </div>
            @if ($orderType === 'dine_in')
                <input type="text" wire:model.blur="tableLabel" maxlength="50" placeholder="No. meja / nama" aria-label="Nomor meja">
            @endif
            {{-- Member (ADR 0013) --}}
            @if ($this->memberProgramEnabled)
                @if ($memberId)
                    <div class="pos-member-chip">
                        <span>★ {{ $memberName }}</span>
                        <button type="button" wire:click="clearMember" aria-label="Lepas member">&times;</button>
                    </div>
                @else
                    <button type="button" class="site-btn site-btn-outline pos-member-btn" wire:click="$set('showMembers', true)">+ Member</button>
                @endif
            @endif
        </div>

        <ul class="pos-lines">
            @forelse ($cart as $i => $line)
                <li wire:key="l-{{ $i }}-{{ $line['product_id'] }}">
                    <button type="button" class="pos-line-main" wire:click="editLine({{ $i }})">
                        <span class="pos-line-name">{{ $line['name'] }}</span>
                        @if ($line['option_names'] !== [] || $line['notes'] || $line['discount'] !== '0.00')
                            <span class="pos-line-meta">
                                {{ implode(', ', $line['option_names']) }}
                                @if ($line['notes']) · “{{ $line['notes'] }}” @endif
                                @if ($line['discount'] !== '0.00') · diskon {{ $money($line['discount']) }} @endif
                            </span>
                        @endif
                    </button>
                    <div class="pos-qty">
                        <button type="button" wire:click="decrement({{ $i }})" aria-label="Kurangi {{ $line['name'] }}">−</button>
                        <span>{{ $line['qty'] }}</span>
                        <button type="button" wire:click="increment({{ $i }})" aria-label="Tambah {{ $line['name'] }}">+</button>
                    </div>
                    <span class="pos-line-total">{{ $money($totals?->lines[$i]['line_total'] ?? null) }}</span>
                </li>
            @empty
                <li class="pos-empty">Pilih produk untuk mulai transaksi</li>
            @endforelse
        </ul>

        @if ($cart !== [])
            <div class="pos-order-extra">
                <div class="pos-discount">
                    <select wire:model.live="discountType" aria-label="Jenis diskon order">
                        <option value="">Tanpa diskon</option>
                        <option value="percent">Diskon %</option>
                        <option value="fixed">Diskon Rp</option>
                    </select>
                    @if ($discountType !== '')
                        <input type="number" min="0" step="any" inputmode="decimal" wire:model.live.debounce.400ms="discountValue" aria-label="Nilai diskon order">
                    @endif
                </div>
                <input type="text" wire:model.blur="notes" maxlength="255" placeholder="Catatan order" aria-label="Catatan order">
                @if (! $billId)
                    <input type="text" wire:model.blur="holdLabel" maxlength="30" placeholder="Penanda pelanggan (opsional, untuk Tahan)" aria-label="Penanda pelanggan">
                @endif
            </div>
        @endif

        @if ($needsApproval && ! $paying)
            @include('livewire.cashier.partials.approval')
        @endif

        @if ($quote['error'])
            <div class="site-alert site-alert-warning" role="alert">{{ $quote['error'] }}</div>
        @endif

        @if ($totals)
            <dl class="pos-totals">
                <dt>Subtotal</dt><dd>{{ $money($totals->grossTotal) }}</dd>
                @if (\App\Support\Money::isPositive($totals->discountTotal))
                    <dt>Diskon</dt><dd>−{{ $money($totals->discountTotal) }}</dd>
                @endif
                @if (\App\Support\Money::isPositive($totals->serviceTotal))
                    <dt>Service</dt><dd>{{ $money($totals->serviceTotal) }}</dd>
                @endif
                @if (\App\Support\Money::isPositive($totals->taxTotal))
                    <dt>Pajak{{ $outlet->tax_inclusive ? ' (termasuk)' : '' }}</dt><dd>{{ $money($totals->taxTotal) }}</dd>
                @endif
                @if (\App\Support\Money::compare($totals->rounding, '0') !== 0)
                    <dt>Pembulatan</dt><dd>{{ $money($totals->rounding) }}</dd>
                @endif
                <dt class="pos-grand">Total</dt><dd class="pos-grand">{{ $money($totals->grandTotal) }}</dd>
                @if (\App\Support\Money::isPositive($billPaid))
                    <dt>Sudah dibayar</dt><dd>{{ $money($billPaid) }}</dd>
                @endif
            </dl>
        @endif

        <div class="pos-confirm" x-data="{ confirming: false }" x-show="confirming" x-cloak x-on:pos-confirm-clear.window="confirming = true" role="alertdialog" aria-label="Kosongkan keranjang?">
            <span>{{ $billId ? 'Tutup open bill ini? Data bill tetap tersimpan.' : 'Kosongkan keranjang?' }}</span>
            <button type="button" class="site-btn site-btn-outline" x-on:click="confirming = false">Batal</button>
            <button type="button" class="site-btn pos-btn-danger" wire:click="clearCart" x-on:click="confirming = false">{{ $billId ? 'Tutup' : 'Kosongkan' }}</button>
        </div>
        <div class="pos-actions">
            <button type="button" class="site-btn site-btn-outline" x-on:click="$dispatch('pos-confirm-clear')" @disabled($cart === [])>{{ $billId ? 'Tutup' : 'Kosongkan' }}</button>
            @unless ($billId)
                <button type="button" class="site-btn site-btn-outline" wire:click="hold" @disabled($cart === [])
                    title="Simpan sementara, layani pelanggan berikutnya">Tahan</button>
            @endunless
            <button type="button" class="site-btn site-btn-outline" wire:click="saveBill" @disabled($cart === [] || $totals === null)>Simpan bill</button>
        </div>
        <button type="button" class="site-btn site-btn-primary pos-pay-btn" wire:click="openPayment" @disabled($totals === null)>
            Bayar {{ $totals ? $money(\App\Support\Money::sub($totals->grandTotal, $billPaid)) : '' }}
        </button>
    </aside>

    {{-- Dialog opsi / ubah item --}}
    @if ($pickProductId)
        @php($pickProduct = $catalog['products']->firstWhere('id', $pickProductId))
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pick-title" wire:keydown.escape="closePicker">
            <form class="pos-modal-card" wire:submit="savePick">
                <h2 id="pick-title">{{ $pickProduct?->name }}</h2>

                @foreach ($this->pickerGroups() as $group)
                    <fieldset class="pos-options" wire:key="g-{{ $group->id }}">
                        <legend>{{ $group->name }} <small>{{ $group->min_select > 0 ? 'wajib' : 'opsional' }}{{ $group->max_select > 1 ? ', maks. '.$group->max_select : '' }}</small></legend>
                        @if ($group->max_select === 1 && $group->min_select === 0)
                            <label class="pos-option">
                                <input type="radio" wire:model="pickOptions.{{ $group->id }}" value="">
                                <span>Tidak dipilih</span>
                            </label>
                        @endif
                        @foreach ($group->options as $option)
                            <label @class(['pos-option', 'is-unavailable' => ! $option->is_available])>
                                @if ($group->max_select === 1)
                                    <input type="radio" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                @else
                                    <input type="checkbox" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                @endif
                                <span>{{ $option->name }}</span>
                                <span class="pos-option-price">{{ \App\Support\Money::isPositive($option->price_delta) ? '+'.$money($option->price_delta) : '' }}{{ $option->is_available ? '' : ' (habis)' }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach

                <div class="pos-modal-row">
                    <label>Qty <input type="number" min="1" max="999" wire:model="pickQty"></label>
                    @can('order.discount')
                        <label>Diskon item (Rp) <input type="number" min="0" step="any" inputmode="decimal" wire:model="pickDiscount"></label>
                    @endcan
                </div>
                <label class="pos-modal-field">Catatan <input type="text" maxlength="255" wire:model="pickNotes" placeholder="mis. less ice"></label>

                @if ($pickError)
                    <p class="site-error" role="alert">{{ $pickError }}</p>
                @endif

                <div class="pos-actions">
                    @if ($pickIndex !== null)
                        <button type="button" class="site-btn site-btn-outline" wire:click="remove({{ $pickIndex }}); closePicker()">Hapus item</button>
                    @endif
                    <button type="button" class="site-btn site-btn-outline" wire:click="closePicker">Batal</button>
                    <button type="submit" class="site-btn site-btn-primary">{{ $pickIndex !== null ? 'Simpan' : 'Tambah' }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Dialog pembayaran --}}
    @if ($paying && $totals)
        @php($remaining = $this->remaining())
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pay-title" wire:keydown.escape="$set('paying', false)">
            <form class="pos-modal-card pos-pay" wire:submit="pay">
                <h2 id="pay-title">Bayar {{ $money(\App\Support\Money::sub($totals->grandTotal, $billPaid)) }}</h2>

                @foreach ($payments as $i => $payment)
                    @php($isCash = $this->isCash($payment['payment_method_id']))
                    <div class="pos-pay-row" wire:key="pay-{{ $payment['id'] }}">
                        <div class="pos-methods" role="radiogroup" aria-label="Metode bayar">
                            @foreach ($catalog['payment_methods'] as $method)
                                @php($active = $payment['payment_method_id'] === $method->id)
                                <button type="button" role="radio" aria-checked="{{ $active ? 'true' : 'false' }}" @class(['pos-method', 'is-active' => $active])
                                    wire:click="setMethod({{ (int) $i }}, '{{ $method->id }}')">
                                    @svg(match ($method->category->value) {
                                        'cash' => 'heroicon-o-banknotes',
                                        'qris' => 'heroicon-o-qr-code',
                                        'transfer' => 'heroicon-o-building-library',
                                        default => 'heroicon-o-credit-card',
                                    })
                                    <span>{{ $method->name }}</span>
                                </button>
                            @endforeach
                        </div>
                        <label>Nominal <input type="number" min="0" step="any" inputmode="decimal" wire:model.live.debounce.400ms="payments.{{ $i }}.amount"></label>
                        @if ($isCash)
                            <label>Uang diterima <input type="number" min="0" step="any" inputmode="decimal" wire:model.live.debounce.400ms="payments.{{ $i }}.tendered" placeholder="{{ $payment['amount'] }}"></label>
                            <div class="pos-quick">
                                {{-- Hanya indeks & kata kunci tetap di ekspresi wire:click (nilai dari browser tidak disisipkan) --}}
                                <button type="button" wire:click="setTendered({{ (int) $i }}, 'exact')">Uang pas</button>
                                @foreach (\App\Livewire\Cashier\PointOfSale::QUICK_CASH as $note)
                                    @if (is_numeric($payment['amount']) && (float) $note >= (float) $payment['amount'])
                                        <button type="button" wire:click="setTendered({{ (int) $i }}, '{{ $note }}')">{{ $money($note) }}</button>
                                    @endif
                                @endforeach
                            </div>
                            @if (is_numeric($payment['tendered']) && is_numeric($payment['amount']) && (float) $payment['tendered'] > (float) $payment['amount'])
                                <p class="pos-change">Kembalian {{ $money(\App\Support\Money::sub(\App\Support\Money::of($payment['tendered']), \App\Support\Money::of($payment['amount']))) }}</p>
                            @endif
                        @else
                            <label>No. referensi <input type="text" maxlength="50" wire:model="payments.{{ $i }}.reference" placeholder="opsional"></label>
                        @endif
                        @if (count($payments) > 1)
                            <button type="button" class="site-link-button" wire:click="removePaymentRow({{ $i }})">Hapus</button>
                        @endif
                    </div>
                @endforeach

                <p @class(['pos-remaining', 'is-done' => ! \App\Support\Money::isPositive($remaining)])>
                    {{ \App\Support\Money::isPositive($remaining) ? 'Sisa '.$money($remaining) : 'Lunas' }}
                </p>

                @if ($payError)
                    <p class="site-error" role="alert">{{ $payError }}</p>
                @endif
                @if ($needsApproval)
                    @include('livewire.cashier.partials.approval')
                @endif

                <div class="pos-actions">
                    <button type="button" class="site-btn site-btn-outline" wire:click="addPaymentRow">+ Split</button>
                    <button type="button" class="site-btn site-btn-outline" wire:click="$set('paying', false)">Batal</button>
                    <button type="submit" class="site-btn site-btn-primary site-btn-lg" wire:loading.attr="disabled" wire:target="pay">Selesaikan</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Daftar open bill --}}
    @if ($showBills)
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="bills-title" wire:keydown.escape="$set('showBills', false)">
            <div class="pos-modal-card">
                <h2 id="bills-title">Open bill</h2>
                @forelse ($this->openBills as $bill)
                    <button type="button" class="site-choice" wire:click="loadBill('{{ $bill->id }}')" wire:key="b-{{ $bill->id }}">
                        <span>
                            <span class="site-choice-name">{{ $bill->table_label ?: $bill->order_number }}</span>
                            <span class="site-choice-meta">{{ $bill->order_number }} · {{ $bill->user?->name }} · {{ $bill->created_at->setTimezone($outlet->timezone)->format('H.i') }}</span>
                        </span>
                        <span class="site-choice-name">{{ $money($bill->grand_total) }}</span>
                    </button>
                @empty
                    <p class="cashier-muted">Belum ada open bill.</p>
                @endforelse
                <div class="pos-actions">
                    <button type="button" class="site-btn site-btn-outline" wire:click="$set('showBills', false)">Tutup</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Pilih / daftar member (ADR 0013) --}}
    @if ($showMembers)
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="members-title" wire:keydown.escape="$set('showMembers', false)">
            <div class="pos-modal-card">
                <h2 id="members-title">Member</h2>
                <input type="search" class="pos-member-search" wire:model.live.debounce.400ms="memberSearch" placeholder="Cari nomor HP, kode, atau nama" aria-label="Cari member" x-init="$nextTick(() => $el.focus())">
                @if ($memberError)
                    <p class="site-error" role="alert">{{ $memberError }}</p>
                @endif

                @foreach ($memberResults as $result)
                    <button type="button" class="site-choice" wire:click="selectMember('{{ $result['id'] }}')" wire:key="m-{{ $result['id'] }}">
                        <span>
                            <span class="site-choice-name">{{ $result['name'] }}</span>
                            <span class="site-choice-meta">{{ $result['code'] }} · {{ $result['phone'] }}</span>
                        </span>
                        <span class="site-choice-meta">{{ $result['visits'] }} kunjungan</span>
                    </button>
                @endforeach

                @if (mb_strlen(trim($memberSearch)) >= 2 && $memberResults === [] && ! $memberError)
                    <p class="cashier-muted">Member tidak ditemukan.</p>
                @endif

                @can('member.create')
                    <form wire:submit="registerMember" class="pos-member-new">
                        <h3>Daftar member baru</h3>
                        <input type="text" wire:model="newMemberName" maxlength="100" placeholder="Nama" aria-label="Nama member" required>
                        <input type="tel" wire:model="newMemberPhone" maxlength="30" placeholder="Nomor HP, mis. 0812 3456 7890" aria-label="Nomor HP" required>
                        <label class="pos-member-consent"><input type="checkbox" wire:model="newMemberConsent"> Pelanggan setuju datanya dipakai untuk program member</label>
                        <button type="submit" class="site-btn site-btn-primary">Daftar &amp; pakai</button>
                    </form>
                @endcan

                <div class="pos-actions">
                    <button type="button" class="site-btn site-btn-outline" wire:click="$set('showMembers', false)">Tutup</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Transaksi selesai --}}
    @if ($completedOrderId)
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="done-title">
            <div class="pos-modal-card pos-done">
                <h2 id="done-title">Transaksi berhasil</h2>
                @if ($completedChange && \App\Support\Money::isPositive($completedChange))
                    <p class="pos-change-big">Kembalian {{ $money($completedChange) }}</p>
                @endif
                <div class="pos-actions">
                    <a href="{{ route('cashier.orders.receipt', ['order' => $completedOrderId, 'print' => 1]) }}" target="_blank" rel="noopener" class="site-btn site-btn-outline">Cetak struk</a>
                    <button type="button" class="site-btn site-btn-primary site-btn-lg" wire:click="newOrder" autofocus>Transaksi baru</button>
                </div>
            </div>
        </div>
    @endif
</div>
