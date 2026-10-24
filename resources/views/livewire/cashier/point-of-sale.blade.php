{{-- Layar transaksi kasir web (Q54). Nominal tampilan dari server (QuoteOrder/CheckoutOrder). --}}
@php
    $money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
    $catalog = $this->catalog;
    $quote = $this->quote;
    $totals = $quote['totals'];
@endphp
<div class="pos">
    {{--
        Katalog = island Livewire 4: tidak ikut di-render ulang saat keranjang berubah (±75% ukuran respons, morph DOM
        berat di tablet). Cari/kategori/Habis dari dalam island hanya me-render island; klik tile memakai $wire (tanpa
        konteks island) sehingga hanya keranjang yang dirender. Server memuat ulang island lewat refreshCatalog().
    --}}
    @island('catalog')
    @php
        $islandMoney = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
        $catalog = $this->catalog;
        $canToggle = $this->canToggleAvailability();
    @endphp
    <section class="pos-catalog" aria-label="Produk">
        <div class="pos-search">
            <input type="search" wire:model.live.debounce.250ms="search" x-on:keydown.enter.prevent="$wire.scan()"
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
                    {{-- Umpan balik instan (is-tapped) sebelum respons server; $wire = render keranjang saja --}}
                    <button type="button" class="pos-tile-main" @disabled(! $product->is_available)
                        x-data x-on:click="$el.classList.remove('is-tapped'); void $el.offsetWidth; $el.classList.add('is-tapped'); $wire.addProduct('{{ $product->id }}')"
                        x-on:animationend="$el.classList.remove('is-tapped')">
                        <span class="pos-tile-media">
                            @if ($image = $product->imageUrl())
                                <img src="{{ $image }}" alt="" class="pos-tile-image" loading="lazy" decoding="async" width="400" height="400">
                            @else
                                <span class="pos-tile-image pos-tile-image--empty" aria-hidden="true">{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}</span>
                            @endif
                            {{-- Habis: badge di atas gambar (gambar diabukan), tile tidak bisa diklik --}}
                            @if (! $product->is_available)
                                <span class="pos-tile-soldout">Habis</span>
                            @endif
                        </span>
                        <span class="pos-tile-name">{{ $product->name }}</span>
                        <span class="pos-tile-price">{{ $islandMoney($product->price) }}</span>
                        @if (! $product->is_available)
                            <span class="pos-tile-flag">Stok habis</span>
                        @elseif ($product->track_stock)
                            <span @class(['pos-tile-stock', 'is-low' => $product->isLowStock()])>Stok {{ $product->stock_qty }}</span>
                        @else
                            {{-- Tempat baris info tetap dipesan agar semua tile sama tinggi --}}
                            <span class="pos-tile-meta" aria-hidden="true"></span>
                        @endif
                    </button>
                    {{-- Tandai habis/tersedia (izin product.toggle_available: supervisor ke atas) --}}
                    @if ($canToggle)
                        <button type="button" @class(['pos-tile-toggle', 'is-restore' => ! $product->is_available])
                            wire:click="toggleAvailability('{{ $product->id }}')"
                            aria-label="{{ $product->is_available ? 'Tandai '.$product->name.' habis' : 'Tandai '.$product->name.' tersedia' }}">
                            @svg($product->is_available ? 'heroicon-o-no-symbol' : 'heroicon-o-arrow-path', ['aria-hidden' => 'true'])
                            <span>{{ $product->is_available ? 'Habis' : 'Tersedia lagi' }}</span>
                        </button>
                    @endif
                </div>
            @empty
                <p class="cashier-muted">Tidak ada produk.</p>
            @endforelse
        </div>
    </section>
    @endisland

    {{-- Keranjang: kepala (tipe order, member) & kaki (total, bayar) tetap; daftar pesanan di tengah yang menggulir --}}
    @php
        $itemCount = array_sum(array_column($cart, 'qty'));
        // Opsi tambahan terlipat; jumlah yang aktif tampil sebagai lencana
        $extraCount = count(array_filter([$discountType !== '', $appliedVoucherCode, $notes, ! $billId && $holdLabel !== '']));
    @endphp
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
                                        @svg('heroicon-o-pause', ['aria-hidden' => 'true']) {{ $h['label'] }} <small>{{ $h['items'] }} item{{ $h['total'] ? ' · '.$money($h['total']) : '' }}</small>
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
            <div class="pos-segment" role="group" aria-label="Tipe order">
                <button type="button" wire:click="$set('orderType', 'takeaway')" @class(['is-active' => $orderType === 'takeaway'])>
                    @svg('heroicon-o-shopping-bag', ['aria-hidden' => 'true']) Bawa pulang
                </button>
                <button type="button" wire:click="$set('orderType', 'dine_in')" @class(['is-active' => $orderType === 'dine_in'])>
                    @svg('heroicon-o-building-storefront', ['aria-hidden' => 'true']) Makan di tempat
                </button>
            </div>
            @if ($billId)
                <span class="pos-bill-tag">{{ $billLocked ? 'Pesanan meja' : 'Open bill' }} {{ $tableLabel ?: '' }}</span>
            @endif
            @if ($orderType === 'dine_in')
                <input type="text" wire:model.blur="tableLabel" maxlength="50" placeholder="No. meja / nama" aria-label="Nomor meja">
            @endif
            {{-- Member (ADR 0013) --}}
            @if ($this->memberProgramEnabled)
                @if ($memberId)
                    <div class="pos-member-chip">
                        <span class="pos-member-chip-name">
                            <span class="pos-member-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($memberName, 0, 1)) }}</span>
                            <span class="pos-member-chip-text">
                                <span class="pos-member-chip-title">{{ $memberName }}
                                    @if ($this->memberTier)
                                        <span class="pos-member-chip-tier">{{ $this->memberTier }}</span>
                                    @endif
                                </span>
                                @if ($this->memberPoints !== null)
                                    <span class="pos-member-chip-points">{{ number_format($this->memberPoints, 0, ',', '.') }} poin</span>
                                @endif
                            </span>
                        </span>
                        <button type="button" class="pos-member-clear" wire:click="clearMember" aria-label="Lepas member">&times;</button>
                        {{-- Kartu stempel (M6) & voucher pribadi (M7): pil kecil di dalam kartu member, satu baris yang digeser --}}
                        @if ($this->stampCards !== [] || $this->memberVouchers !== [])
                            <div class="pos-stamps" role="group" aria-label="Kartu stempel & voucher member">
                                @foreach ($this->stampCards as $card)
                                    @php($used = in_array($card['id'], $stampProgramIds, true))
                                    @php($ready = $card['rewards_available'] > 0)
                                    <button type="button" wire:key="stamp-{{ $card['id'] }}" wire:click="toggleStamp('{{ $card['id'] }}')"
                                        @class(['pos-stamp', 'is-ready' => $ready, 'is-active' => $used]) aria-pressed="{{ $used ? 'true' : 'false' }}"
                                        @disabled(! $used && ! $ready)
                                        title="{{ $card['name'] }}: {{ $card['balance'] }}/{{ $card['stamps_required'] }} stempel">
                                        @svg('heroicon-o-gift', ['aria-hidden' => 'true'])
                                        <span class="pos-stamp-name">{{ $card['name'] }}</span>
                                        <span class="pos-stamp-count">{{ $used ? 'dipakai' : ($ready ? 'pakai gratis' : $card['balance'].'/'.$card['stamps_required']) }}</span>
                                    </button>
                                @endforeach
                                @foreach ($this->memberVouchers as $voucher)
                                    @php($used = $voucherId === $voucher['id'] && $appliedVoucherCode === null)
                                    <button type="button" wire:key="mv-{{ $voucher['id'] }}" wire:click="togglePersonalVoucher('{{ $voucher['id'] }}')"
                                        @class(['pos-stamp', 'is-ready', 'is-active' => $used]) aria-pressed="{{ $used ? 'true' : 'false' }}">
                                        @svg('heroicon-o-ticket', ['aria-hidden' => 'true'])
                                        <span class="pos-stamp-name">{{ $voucher['label'] }}</span>
                                        <span class="pos-stamp-count">{{ $used ? 'dipakai' : 'pakai' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    {{-- Kode member / nomor HP: Enter (atau scanner barcode) langsung memasang bila cocok persis --}}
                    <div class="pos-member-field">
                        <div class="pos-member-input">
                            <input type="search" wire:model.live.debounce.400ms="memberSearch" wire:keydown.enter.prevent="pickMember"
                                placeholder="Member: kode / no. HP" aria-label="Cari member (kode atau nomor HP)" autocomplete="off">
                            @can('member.create')
                                <button type="button" wire:click="openMemberRegistration" aria-label="Daftar member baru" title="Daftar member baru">
                                    @svg('heroicon-o-user-plus', ['aria-hidden' => 'true'])
                                </button>
                            @endcan
                        </div>
                        @if ($memberError)
                            <p class="pos-member-error" role="alert">{{ $memberError }}</p>
                        @endif
                        @if ($memberResults !== [])
                            <ul class="pos-member-results" role="listbox" aria-label="Hasil member">
                                @foreach ($memberResults as $result)
                                    <li wire:key="m-{{ $result['id'] }}">
                                        <button type="button" role="option" wire:click="selectMember('{{ $result['id'] }}')">
                                            <span class="pos-member-result-name">{{ $result['name'] }}</span>
                                            <span class="pos-member-result-meta">{{ $result['code'] }} · {{ $result['phone'] }}{{ $result['last_visit'] ? ' · terakhir '.$result['last_visit'] : '' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($memberSearch)) >= 2 && ! $memberError)
                            <p class="pos-member-error">Tidak ditemukan. Tekan ikon + untuk mendaftarkan.</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        <div class="pos-cart-body">
            <div class="pos-cart-title">
                <h2>Detail pesanan @if ($cart !== [])<span>{{ $itemCount }} item</span>@endif</h2>
                <button type="button" class="pos-bills-btn" wire:click="$set('showBills', true)">
                    @svg('heroicon-o-document-text', ['aria-hidden' => 'true']) Open bill
                    <span class="pos-count-badge">{{ $this->openBills->count() }}</span>
                </button>
            </div>
            <ul class="pos-lines">
                @forelse ($cart as $i => $line)
                    <li wire:key="l-{{ $i }}-{{ $line['product_id'] }}">
                        <div class="pos-qty">
                            <button type="button" wire:click="decrement({{ $i }})" aria-label="Kurangi {{ $line['name'] }}">@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                            <span>{{ $line['qty'] }}</span>
                            <button type="button" wire:click="increment({{ $i }})" aria-label="Tambah {{ $line['name'] }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                        </div>
                        <button type="button" class="pos-line-main" wire:click="editLine({{ $i }})" title="Ubah {{ $line['name'] }}">
                            <span class="pos-line-name">{{ $line['name'] }}</span>
                            @if ($line['option_names'] !== [] || $line['notes'] || $line['discount'] !== '0.00')
                                <span class="pos-line-meta">
                                    {{ implode(', ', $line['option_names']) }}
                                    @if ($line['notes']) · “{{ $line['notes'] }}” @endif
                                    @if ($line['discount'] !== '0.00') · diskon {{ $money($line['discount']) }} @endif
                                </span>
                            @endif
                        </button>
                        <span class="pos-line-total">{{ $money($totals?->lines[$i]['line_total'] ?? null) }}</span>
                    </li>
                @empty
                    <li class="pos-empty">
                        @svg('heroicon-o-shopping-cart', ['aria-hidden' => 'true'])
                        <span>Pilih produk untuk mulai transaksi</span>
                    </li>
                @endforelse
            </ul>

            @if ($cart !== [])
                {{-- Diskon, voucher, catatan: terlipat agar daftar pesanan tetap terlihat di tablet --}}
                <div class="pos-extra" x-data="{ open: @js($extraCount > 0) }">
                    <button type="button" class="pos-extra-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open">
                        @svg('heroicon-o-receipt-percent', ['aria-hidden' => 'true'])
                        <span>Diskon, voucher & catatan</span>
                        @if ($extraCount > 0)
                            <span class="pos-count-badge">{{ $extraCount }}</span>
                        @endif
                        @svg('heroicon-o-chevron-down', ['class' => 'pos-extra-chevron', 'aria-hidden' => 'true', 'x-bind:class' => "open && 'is-open'"])
                    </button>
                    <div class="pos-order-extra" x-show="open" x-cloak>
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
                        {{-- Kode promo (M7): dicek server saat Pakai; potongan masuk pratinjau total --}}
                        @if ($this->memberProgramEnabled)
                            @if ($appliedVoucherCode)
                                <div class="pos-member-chip">
                                    <span class="pos-member-chip-name">Voucher {{ $appliedVoucherCode }}</span>
                                    <button type="button" wire:click="removeVoucher" aria-label="Lepas voucher">&times;</button>
                                </div>
                            @else
                                <form class="pos-discount" wire:submit="applyVoucher">
                                    <input type="text" wire:model="voucherInput" maxlength="30" placeholder="Kode voucher" aria-label="Kode voucher" autocomplete="off">
                                    <button type="submit" class="site-btn site-btn-outline">Pakai</button>
                                </form>
                            @endif
                        @endif
                        <input type="text" wire:model.blur="notes" maxlength="255" placeholder="Catatan order" aria-label="Catatan order">
                        @if (! $billId)
                            <input type="text" wire:model.blur="holdLabel" maxlength="30" placeholder="Penanda pelanggan (opsional, untuk Tahan)" aria-label="Penanda pelanggan">
                        @endif
                    </div>
                    @if ($voucherError)
                        <p class="pos-member-error" role="alert">{{ $voucherError }}</p>
                    @endif
                </div>
            @endif

            @if ($needsApproval && ! $paying)
                @include('livewire.cashier.partials.approval')
            @endif

            @if ($quote['error'])
                <div class="site-alert site-alert-warning" role="alert">{{ $quote['error'] }}</div>
            @endif
        </div>

        <div class="pos-cart-foot">
            @if ($totals)
                {{-- Rincian terlipat di layar pendek (tablet landscape) agar detail pesanan lebih lega; Total selalu tampil --}}
                <dl class="pos-totals" x-data="{ open: window.innerHeight > 860 }" x-bind:class="open && 'is-open'">
                    <dt>Subtotal</dt><dd>{{ $money($totals->grossTotal) }}</dd>
                    @if (\App\Support\Money::isPositive($totals->manualDiscount))
                        <dt>Diskon</dt><dd class="is-minus">−{{ $money($totals->manualDiscount) }}</dd>
                    @endif
                    @if (\App\Support\Money::isPositive($totals->stampDiscount))
                        <dt>Gratis stempel</dt><dd class="is-minus">−{{ $money($totals->stampDiscount) }}</dd>
                    @endif
                    {{-- Promo otomatis (ADR 0022) --}}
                    @foreach ($totals->promotions as $promo)
                        <dt>{{ $promo['name'] }}</dt><dd class="is-minus">−{{ $money($promo['amount']) }}</dd>
                    @endforeach
                    @if (\App\Support\Money::isPositive($totals->voucherDiscount))
                        <dt>Voucher</dt><dd class="is-minus">−{{ $money($totals->voucherDiscount) }}</dd>
                    @endif
                    @if (\App\Support\Money::isPositive($totals->memberDiscount))
                        <dt>Diskon member{{ $this->memberTier ? ' · '.$this->memberTier : '' }}</dt><dd class="is-minus">−{{ $money($totals->memberDiscount) }}</dd>
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
                    <dt class="pos-grand">
                        <button type="button" class="pos-totals-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open">
                            Total @svg('heroicon-o-chevron-down', ['aria-hidden' => 'true'])
                        </button>
                    </dt>
                    <dd class="pos-grand">{{ $money($totals->grandTotal) }}</dd>
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
            {{-- Aksi sekunder sebaris (ikon + label), tombol Bayar selebar panel di bawahnya --}}
            <div class="pos-actions">
                <button type="button" class="pos-action" x-on:click="$dispatch('pos-confirm-clear')" @disabled($cart === [])>
                    @svg($billId ? 'heroicon-o-x-mark' : 'heroicon-o-trash', ['aria-hidden' => 'true'])
                    <span>{{ $billId ? 'Tutup' : 'Kosongkan' }}</span>
                </button>
                @unless ($billId)
                    <button type="button" class="pos-action" wire:click="hold" @disabled($cart === [])
                        title="Simpan sementara, layani pelanggan berikutnya">
                        @svg('heroicon-o-pause', ['aria-hidden' => 'true'])
                        <span>Tahan</span>
                    </button>
                @endunless
                @unless ($billLocked)
                    <button type="button" class="pos-action" wire:click="saveBill" @disabled($cart === [] || $totals === null)>
                        @svg('heroicon-o-bookmark', ['aria-hidden' => 'true'])
                        <span>Simpan bill</span>
                    </button>
                @endunless
            </div>
            <button type="button" class="site-btn site-btn-primary pos-pay-btn" wire:click="openPayment" @disabled($totals === null)>
                @svg('heroicon-o-banknotes', ['aria-hidden' => 'true'])
                <span>Bayar</span>
                @if ($totals)
                    <strong>{{ $money(\App\Support\Money::sub($totals->grandTotal, $billPaid)) }}</strong>
                @endif
            </button>
        </div>
    </aside>

    {{-- Dialog opsi / ubah item: kartu opsi (ketuk untuk memilih), stepper qty seperti detail pesanan --}}
    @if ($pickProductId)
        @php($pickProduct = $catalog['products']->firstWhere('id', $pickProductId))
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pick-title" wire:keydown.escape="closePicker">
            <form class="pos-modal-card pos-pick" wire:submit="savePick">
                <header class="pos-pick-head">
                    @if ($pickProduct && ($image = $pickProduct->imageUrl()))
                        <img src="{{ $image }}" alt="" class="pos-pick-image" width="56" height="56">
                    @endif
                    <div>
                        <h2 id="pick-title">{{ $pickProduct?->name }}</h2>
                        @if ($pickProduct)
                            <span>{{ $money($pickProduct->price) }}</span>
                        @endif
                    </div>
                    <button type="button" class="pos-pick-close" wire:click="closePicker" aria-label="Tutup">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                </header>

                @foreach ($this->pickerGroups() as $group)
                    @php($chosen = (array) ($pickOptions[$group->id] ?? []))
                    <fieldset class="pos-options" wire:key="g-{{ $group->id }}">
                        <legend>
                            {{ $group->name }}
                            <span @class(['pos-options-badge', 'is-required' => $group->min_select > 0])>
                                {{ $group->min_select > 0 ? 'Wajib' : 'Opsional' }}{{ $group->max_select > 1 ? ' · maks. '.$group->max_select : '' }}
                            </span>
                        </legend>
                        <div class="pos-option-grid" role="{{ $group->max_select === 1 ? 'radiogroup' : 'group' }}" aria-label="{{ $group->name }}">
                            @foreach ($group->options as $option)
                                @php($on = in_array($option->id, $chosen, true))
                                <button type="button" wire:key="o-{{ $option->id }}" wire:click="pickOption('{{ $group->id }}', '{{ $option->id }}')"
                                    role="{{ $group->max_select === 1 ? 'radio' : 'checkbox' }}" aria-checked="{{ $on ? 'true' : 'false' }}"
                                    @class(['pos-choice', 'is-selected' => $on, 'is-unavailable' => ! $option->is_available]) @disabled(! $option->is_available)>
                                    <span class="pos-choice-name">{{ $option->name }}</span>
                                    <span class="pos-choice-price">
                                        @if (! $option->is_available)
                                            Habis
                                        @elseif (\App\Support\Money::isPositive($option->price_delta))
                                            +{{ $money($option->price_delta) }}
                                        @else
                                            Gratis
                                        @endif
                                    </span>
                                    <span class="pos-choice-check" aria-hidden="true">@svg('heroicon-s-check', ['aria-hidden' => 'true'])</span>
                                </button>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <div class="pos-pick-row">
                    <div class="pos-modal-field">
                        <span>Jumlah</span>
                        <div class="pos-stepper">
                            <button type="button" wire:click="stepPickQty(-1)" @disabled($pickQty <= 1) aria-label="Kurangi jumlah">@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                            <input type="number" min="1" max="999" inputmode="numeric" wire:model.live.debounce.300ms="pickQty" aria-label="Jumlah">
                            <button type="button" wire:click="stepPickQty(1)" @disabled($pickQty >= 999) aria-label="Tambah jumlah">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                        </div>
                    </div>
                    @can('order.discount')
                        {{-- Input Rupiah sama dengan dialog bayar (pemisah ribuan, satu garis fokus, tanpa panah angka) --}}
                        <label class="pos-modal-field">Diskon item
                            <span class="pos-money is-compact" x-data="posMoney" x-modelable="raw" wire:model="pickDiscount">
                                <span class="pos-money-prefix">Rp</span>
                                <input type="text" inputmode="numeric" autocomplete="off" placeholder="0" aria-label="Diskon item (Rupiah)"
                                    x-bind:value="display" x-on:input.stop="type($event)" x-on:change.stop>
                            </span>
                        </label>
                    @endcan
                </div>
                <label class="pos-modal-field">Catatan <input type="text" maxlength="255" wire:model="pickNotes" placeholder="mis. less ice, tanpa sedotan"></label>

                @if ($pickError)
                    <p class="site-error" role="alert">{{ $pickError }}</p>
                @endif

                <div class="pos-pick-actions">
                    @if ($pickIndex !== null)
                        <button type="button" class="site-btn site-btn-outline pos-btn-ghost-danger" wire:click="remove({{ $pickIndex }}); closePicker()">@svg('heroicon-o-trash', ['aria-hidden' => 'true']) Hapus</button>
                    @endif
                    <button type="button" class="site-btn site-btn-outline" wire:click="closePicker">Batal</button>
                    <button type="submit" class="site-btn site-btn-primary">@svg($pickIndex !== null ? 'heroicon-o-check' : 'heroicon-o-plus', ['aria-hidden' => 'true']) {{ $pickIndex !== null ? 'Simpan' : 'Tambah ke pesanan' }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Dialog pembayaran --}}
    @if ($paying && $totals)
        @php($state = $this->paymentState())
        @php($split = count($payments) > 1)
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="pay-title" wire:keydown.escape="$set('paying', false)">
            <form class="pos-modal-card pos-pay" wire:submit="pay">
                <header class="pos-pay-head">
                    <span>Total tagihan</span>
                    <h2 id="pay-title">{{ $money(\App\Support\Money::sub($totals->grandTotal, $billPaid)) }}</h2>
                    @if ($memberId)
                        <span class="pos-pay-member">@svg('heroicon-o-user', ['aria-hidden' => 'true']) {{ $memberName }}</span>
                    @endif
                </header>

                @foreach ($payments as $i => $payment)
                    @php($isCash = $this->isCash($payment['payment_method_id']))
                    <div @class(['pos-pay-row', 'is-split' => $split]) wire:key="pay-{{ $payment['id'] }}">
                        @if ($split)
                            <div class="pos-pay-row-head">
                                <span>Pembayaran {{ $i + 1 }}</span>
                                <button type="button" class="site-link-button" wire:click="removePaymentRow({{ $i }})">Hapus</button>
                            </div>
                        @endif
                        <div class="pos-methods" role="radiogroup" aria-label="Metode bayar">
                            @foreach ($this->paymentMethods as $method)
                                @php($active = $payment['payment_method_id'] === $method->id)
                                <button type="button" role="radio" aria-checked="{{ $active ? 'true' : 'false' }}" @class(['pos-method', 'is-active' => $active])
                                    wire:click="setMethod({{ (int) $i }}, '{{ $method->id }}')">
                                    @svg(match ($method->category->value) {
                                        'cash' => 'heroicon-o-banknotes',
                                        'qris' => 'heroicon-o-qr-code',
                                        'transfer' => 'heroicon-o-building-library',
                                        'points' => 'heroicon-o-star',
                                        default => 'heroicon-o-credit-card',
                                    })
                                    <span>{{ $method->name }}</span>
                                </button>
                            @endforeach
                        </div>

                        {{-- Satu metode: nominal = seluruh tagihan (tidak perlu diketik); split: nominal per metode --}}
                        @if ($split)
                            <label class="pos-money-field">
                                <span>Nominal</span>
                                <span class="pos-money" x-data="posMoney" x-modelable="raw" wire:model.live.debounce.400ms="payments.{{ $i }}.amount">
                                    <span class="pos-money-prefix">Rp</span>
                                    <input type="text" inputmode="decimal" autocomplete="off" placeholder="0" x-bind:value="display" x-on:input.stop="type($event)" x-on:change.stop>
                                </span>
                            </label>
                        @endif

                        @if ($isCash)
                            <label class="pos-money-field is-large">
                                <span>Uang diterima</span>
                                <span class="pos-money" x-data="posMoney" x-modelable="raw" wire:model.live.debounce.400ms="payments.{{ $i }}.tendered">
                                    <span class="pos-money-prefix">Rp</span>
                                    <input type="text" inputmode="decimal" autocomplete="off" placeholder="0" x-bind:value="display" x-on:input.stop="type($event)" x-on:change.stop
                                        @if ($i === 0) x-init="$nextTick(() => $el.focus())" @endif>
                                </span>
                            </label>
                            <div class="pos-quick" aria-label="Nominal cepat">
                                {{-- Hanya indeks & nilai saran server di ekspresi wire:click (dicocokkan ulang di setTendered) --}}
                                <button type="button" wire:click="setTendered({{ (int) $i }}, 'exact')">Uang pas</button>
                                @foreach (\App\Livewire\Cashier\PointOfSale::cashSuggestions($payment['amount']) as $note)
                                    <button type="button" wire:click="setTendered({{ (int) $i }}, '{{ $note }}')">{{ $money($note) }}</button>
                                @endforeach
                            </div>
                        @elseif (! $this->needsMemberProof($payment['payment_method_id']))
                            <label class="pos-money-field">
                                <span>No. referensi <small>(opsional)</small></span>
                                <input type="text" maxlength="50" wire:model="payments.{{ $i }}.reference" placeholder="mis. 4 digit terakhir / ID transaksi">
                            </label>
                        @endif
                    </div>
                @endforeach

                {{-- Bayar dengan poin (ADR 0013) / kasbon (ADR 0023): bukti pemilik dari halaman member atau PIN member --}}
                @if ($this->usesPoints())
                    <div class="pos-member-proof">
                        <div class="pos-member-proof-head">
                            <span class="pos-member-proof-icon" aria-hidden="true">@svg('heroicon-o-shield-check')</span>
                            <div>
                                <strong>Verifikasi member</strong>
                                <span>
                                    Saldo {{ number_format((int) $this->memberPoints, 0, ',', '.') }} poin
                                    @if ($this->memberCredit)
                                        · kasbon {{ \App\Support\Money::format($this->memberCredit['balance']) }} dari batas {{ \App\Support\Money::format($this->memberCredit['limit']) }}
                                    @endif
                                </span>
                            </div>
                        </div>
                        <p>Minta pelanggan menunjukkan kode QR di halaman member, atau memasukkan PIN member.</p>
                        <div class="pos-member-proof-fields">
                            <label class="pos-proof-field">
                                <span>Kode QR</span>
                                <input type="text" inputmode="numeric" maxlength="6" wire:model.live.debounce.300ms="memberCode" autocomplete="off" placeholder="6 digit">
                            </label>
                            <span class="pos-member-proof-or">atau</span>
                            <label class="pos-proof-field">
                                <span>PIN member</span>
                                <input type="password" inputmode="numeric" maxlength="4" wire:model.live.debounce.300ms="memberPin" autocomplete="off" placeholder="4 digit">
                            </label>
                        </div>
                    </div>
                @endif

                {{-- Ringkasan: kembalian besar bila siap; selain itu langkah berikutnya --}}
                <div @class(['pos-pay-status', 'is-'.$state['tone']]) role="status" aria-live="polite">
                    @if ($state['ready'] && \App\Support\Money::isPositive($state['change']))
                        <span>Kembalian</span>
                        <strong>{{ $money($state['change']) }}</strong>
                    @else
                        <span>{{ $state['ready'] ? 'Pembayaran' : 'Belum bisa diselesaikan' }}</span>
                        <strong>{{ $state['message'] }}</strong>
                    @endif
                </div>

                @if ($payError)
                    <p class="site-error" role="alert">{{ $payError }}</p>
                @endif
                @if ($needsApproval)
                    @include('livewire.cashier.partials.approval')
                @endif

                <div class="pos-pay-actions">
                    <button type="button" class="site-btn site-btn-outline" wire:click="addPaymentRow">@svg('heroicon-o-plus', ['aria-hidden' => 'true']) Split</button>
                    <button type="button" class="site-btn site-btn-outline" wire:click="$set('paying', false)">Batal</button>
                    <button type="submit" class="site-btn site-btn-primary site-btn-lg" wire:loading.attr="disabled" wire:target="pay" @disabled(! $state['ready'])>
                        @svg('heroicon-o-check', ['aria-hidden' => 'true']) Selesaikan
                    </button>
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
                            <span class="site-choice-meta">{{ $bill->order_number }} · {{ $bill->user?->name }} · {{ $bill->created_at->setTimezone($outlet->timezone)->format('H:i') }}</span>
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

    {{-- Daftar member baru (ADR 0013) --}}
    @if ($showMembers)
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="members-title" wire:keydown.escape="$set('showMembers', false)">
            <div class="pos-modal-card">
                <h2 id="members-title">Daftar member baru</h2>
                <form wire:submit="registerMember" class="pos-member-new">
                    <input type="text" wire:model="newMemberName" maxlength="100" placeholder="Nama" aria-label="Nama member" required x-init="$nextTick(() => $el.focus())">
                    <input type="tel" wire:model="newMemberPhone" maxlength="30" placeholder="Nomor HP, mis. 0812 3456 7890" aria-label="Nomor HP" required>
                    <label class="pos-member-consent"><input type="checkbox" wire:model="newMemberConsent"> Pelanggan setuju datanya dipakai untuk program member</label>
                    @if ($memberError)
                        <p class="site-error" role="alert">{{ $memberError }}</p>
                    @endif
                    <div class="pos-actions">
                        <button type="button" class="site-btn site-btn-outline" wire:click="$set('showMembers', false)">Batal</button>
                        <button type="submit" class="site-btn site-btn-primary">Daftar &amp; pakai</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Transaksi selesai (Q129): tanda sukses, kembalian menonjol, ringkasan pembayaran, cetak struk / transaksi baru --}}
    @if ($completedOrderId)
        @php($sum = $completedSummary)
        @php($hasChange = $completedChange && \App\Support\Money::isPositive($completedChange))
        <div class="pos-modal" role="dialog" aria-modal="true" aria-labelledby="done-title">
            <div class="pos-modal-card pos-done">
                <div class="pos-done-mark" aria-hidden="true">@svg('heroicon-o-check', ['aria-hidden' => 'true'])</div>
                <h2 id="done-title">Pembayaran berhasil</h2>
                @if ($sum)
                    <p class="pos-done-meta"><span class="pos-done-number">{{ $sum['number'] }}</span> · {{ $sum['at'] }}</p>
                @endif

                @if ($hasChange)
                    <div class="pos-done-change">
                        <span>Kembalian</span>
                        <strong>{{ $money($completedChange) }}</strong>
                    </div>
                @else
                    <div class="pos-done-change is-exact">
                        <span>Uang pas</span>
                        <strong>Tanpa kembalian</strong>
                    </div>
                @endif

                @if ($sum)
                    <dl class="pos-done-lines">
                        <div><dt>Total belanja</dt><dd>{{ $money($sum['total']) }}</dd></div>
                        @foreach ($sum['methods'] as $method)
                            <div wire:key="done-m-{{ $loop->index }}"><dt>{{ $method['name'] }}</dt><dd>{{ $money($method['amount']) }}</dd></div>
                        @endforeach
                        <div class="is-muted"><dt>{{ $sum['type'] }}{{ $sum['table'] ? ' · '.$sum['table'] : '' }}</dt><dd>{{ $sum['items'] }} item</dd></div>
                        @if ($sum['member'])
                            <div class="is-muted"><dt>Member</dt><dd>{{ $sum['member'] }}</dd></div>
                        @endif
                    </dl>
                @endif

                <div class="pos-done-actions">
                    <a href="{{ route('cashier.orders.receipt', ['order' => $completedOrderId, 'print' => 1]) }}" target="_blank" rel="noopener" class="site-btn site-btn-outline">
                        @svg('heroicon-o-printer', ['aria-hidden' => 'true']) Cetak struk
                    </a>
                    {{-- Fokus otomatis (dialog dirender Livewire, atribut autofocus saja tidak cukup): Enter = transaksi baru --}}
                    <button type="button" class="site-btn site-btn-primary" wire:click="newOrder" autofocus x-data x-init="$nextTick(() => $el.focus())">
                        @svg('heroicon-o-plus', ['aria-hidden' => 'true']) Transaksi baru
                    </button>
                </div>
                <p class="pos-done-hint">Tekan Enter untuk transaksi baru</p>
            </div>
        </div>
    @endif
</div>
