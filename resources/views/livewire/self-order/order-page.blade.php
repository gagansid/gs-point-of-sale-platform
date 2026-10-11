{{-- Halaman pesan dari meja (ADR 0014): menu → keranjang → status. Semua teks pengguna di-escape. --}}
@php
    $money = fn (string $v): string => \App\Support\Money::format($v);
    $quote = $this->quote;
    $count = (int) array_sum(array_column($cart, 'qty'));
    $member = $this->member();
@endphp
<div class="self-order">
    @if ($error)
        <div class="self-order-alert" role="alert">{{ $error }}</div>
    @endif

    <nav class="self-order-tabs" aria-label="Langkah pesanan">
        <button type="button" wire:click="show('menu')" @class(['is-active' => $view === 'menu'])>Menu</button>
        <button type="button" wire:click="show('cart')" @class(['is-active' => $view === 'cart'])>Keranjang{{ $count > 0 ? ' ('.$count.')' : '' }}</button>
        <button type="button" wire:click="show('status')" @class(['is-active' => $view === 'status'])>Pesananku{{ $this->myOrders->isNotEmpty() ? ' ('.$this->myOrders->count().')' : '' }}</button>
    </nav>

    @if ($view === 'menu')
        @if ($context->setting->welcome_text)
            <p class="member-muted self-order-welcome">{{ $context->setting->welcome_text }}</p>
        @endif

        @if ($this->catalog['categories']->count() > 1)
            <div class="self-order-categories" role="group" aria-label="Kategori">
                <button type="button" wire:click="$set('category', '')" @class(['is-active' => $category === ''])>Semua</button>
                @foreach ($this->catalog['categories'] as $cat)
                    <button type="button" wire:key="c-{{ $cat->id }}" wire:click="$set('category', '{{ $cat->id }}')" @class(['is-active' => $category === $cat->id])>{{ $cat->name }}</button>
                @endforeach
            </div>
        @endif

        <ul class="self-order-menu">
            @forelse ($this->visibleProducts() as $product)
                <li wire:key="p-{{ $product->id }}">
                    <button type="button" wire:click="addProduct('{{ $product->id }}')" @disabled(! $product->is_available) @class(['is-unavailable' => ! $product->is_available])>
                        @if ($product->imageUrl())
                            <img src="{{ $product->imageUrl() }}" alt="" loading="lazy">
                        @endif
                        <span class="self-order-menu-name">{{ $product->name }}</span>
                        <span class="self-order-menu-price">{{ $product->is_available ? $money($product->price) : 'Habis' }}</span>
                    </button>
                </li>
            @empty
                <li class="member-muted">Menu belum tersedia.</li>
            @endforelse
        </ul>

        @if ($count > 0)
            <button type="button" class="site-btn site-btn-primary self-order-floating" wire:click="show('cart')">
                Lihat keranjang · {{ $count }} item{{ $quote['totals'] ? ' · '.$money($quote['totals']->grandTotal) : '' }}
            </button>
        @endif
    @elseif ($view === 'cart')
        <section class="member-card">
            <h2 class="member-subtitle">Keranjang · {{ $context->table->name }}</h2>
            @if ($cart === [])
                <p class="member-muted">Belum ada pesanan. Pilih dari menu.</p>
            @else
                <ul class="self-order-lines">
                    @foreach ($cart as $i => $line)
                        <li wire:key="l-{{ $i }}">
                            <div>
                                <strong>{{ $line['name'] }}</strong>
                                @if ($line['option_names'] !== [] || $line['notes'])
                                    <span class="member-muted">{{ implode(', ', $line['option_names']) }}{{ $line['notes'] ? ' · '.$line['notes'] : '' }}</span>
                                @endif
                            </div>
                            <div class="self-order-qty">
                                <button type="button" wire:click="decrement({{ $i }})" aria-label="Kurangi">−</button>
                                <span>{{ $line['qty'] }}</span>
                                <button type="button" wire:click="increment({{ $i }})" aria-label="Tambah">+</button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($quote['error'])
                    <p class="site-error" role="alert">{{ $quote['error'] }}</p>
                @elseif ($quote['totals'])
                    @php($t = $quote['totals'])
                    <dl class="self-order-totals">
                        <dt>Subtotal</dt><dd>{{ $money($t->subtotal) }}</dd>
                        @if (\App\Support\Money::isPositive($t->voucherDiscount))
                            <dt>Voucher</dt><dd>−{{ $money($t->voucherDiscount) }}</dd>
                        @endif
                        @if (\App\Support\Money::isPositive($t->memberDiscount))
                            <dt>Diskon member</dt><dd>−{{ $money($t->memberDiscount) }}</dd>
                        @endif
                        @if (\App\Support\Money::isPositive($t->serviceTotal))
                            <dt>Service</dt><dd>{{ $money($t->serviceTotal) }}</dd>
                        @endif
                        @if (\App\Support\Money::isPositive($t->taxTotal))
                            <dt>Pajak</dt><dd>{{ $money($t->taxTotal) }}</dd>
                        @endif
                        <dt class="self-order-grand">Total</dt><dd class="self-order-grand">{{ $money($t->grandTotal) }}</dd>
                    </dl>
                @endif

                <form class="member-form" wire:submit="submit">
                    <label class="member-field">
                        <span>Nama pemesan</span>
                        <input type="text" wire:model="customerName" maxlength="100" required autocomplete="name">
                    </label>
                    <label class="member-field">
                        <span>Catatan untuk kasir (opsional)</span>
                        <input type="text" wire:model="notes" maxlength="255">
                    </label>
                    <label class="member-field">
                        <span>Kode voucher (opsional)</span>
                        <input type="text" wire:model.blur="voucherCode" maxlength="30" autocomplete="off">
                    </label>
                    @if ($member)
                        <p class="member-muted">Masuk sebagai member <strong>{{ $member->name }}</strong> — poin & diskon member ikut dihitung.</p>
                    @else
                        <p class="member-muted"><a href="{{ route('self-order.member', ['token' => $context->table->qr_token]) }}">Masuk sebagai member</a> untuk dapat poin & diskon.</p>
                    @endif
                    <button type="submit" class="site-btn site-btn-primary" wire:loading.attr="disabled">Kirim pesanan</button>
                    <p class="member-muted">Kasir memeriksa pesanan dulu, setelah itu kamu bisa membayar.</p>
                </form>
            @endif
        </section>
    @else
        <section wire:poll.5s>
            @forelse ($this->myOrders as $selfOrder)
                @php($status = $statusLabel($selfOrder))
                <article class="member-card self-order-status" wire:key="s-{{ $selfOrder->id }}">
                    <div class="self-order-status-head">
                        <h2 class="member-subtitle">{{ $selfOrder->order?->order_number ?? 'Pesanan '.$selfOrder->created_at->setTimezone($context->outlet->timezone)->format('H.i') }}</h2>
                        <span @class(['self-order-badge', 'is-'.$status['tone']])>{{ $status['label'] }}</span>
                    </div>
                    <p class="member-muted">{{ $selfOrder->itemCount() }} item · {{ $money($selfOrder->order->grand_total ?? $selfOrder->estimated_total) }}@if ($selfOrder->order && \App\Support\Money::isPositive($selfOrder->order->paid_total) && $selfOrder->order->status === \App\Enums\OrderStatus::AwaitingPayment) · sisa {{ $money(\App\Support\Money::sub($selfOrder->order->grand_total, $selfOrder->order->paid_total)) }}@endif</p>
                    @if ($status['note'] !== '')
                        <p>{{ $status['note'] }}</p>
                    @endif
                    @if ($selfOrder->order?->status === \App\Enums\OrderStatus::AwaitingPayment)
                        <div class="self-order-pay">
                            @if (($points = $this->pointsFor($selfOrder)) > 0)
                                <button type="button" class="site-btn site-btn-outline" wire:click="payWithPoints('{{ $selfOrder->id }}')" wire:loading.attr="disabled">
                                    Pakai {{ number_format($points, 0, ',', '.') }} poin ({{ $money(\App\Support\Money::mul((string) \App\Models\MemberSetting::current()->redeem_value, $points)) }})
                                </button>
                            @endif
                            @if ($this->onlineReady)
                                <button type="button" class="site-btn site-btn-primary" wire:click="payOnline('{{ $selfOrder->id }}')" wire:loading.attr="disabled">Bayar online (QRIS / e-wallet)</button>
                            @endif
                            @if ($context->setting->allow_pay_at_cashier || ! $this->onlineReady)
                                <p class="member-muted">{{ $this->onlineReady ? 'Atau bayar' : 'Bayar' }} di kasir dengan menyebut nomor pesanan.</p>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <section class="member-card"><p class="member-muted">Belum ada pesanan dari HP ini.</p></section>
            @endforelse
            <button type="button" class="site-btn site-btn-outline" wire:click="show('menu')">Pesan lagi</button>
        </section>
    @endif

    {{-- Dialog opsi --}}
    @if ($pickProductId)
        @php($pickProduct = $this->catalog['products']->firstWhere('id', $pickProductId))
        <div class="self-order-sheet" role="dialog" aria-modal="true" aria-labelledby="pick-title">
            <form class="member-card" wire:submit="savePick">
                <h2 id="pick-title" class="member-subtitle">{{ $pickProduct?->name }} · {{ $pickProduct ? $money($pickProduct->price) : '' }}</h2>

                @foreach ($this->pickerGroups() as $group)
                    <fieldset class="self-order-options" wire:key="g-{{ $group->id }}">
                        <legend>{{ $group->name }} <small>{{ $group->min_select > 0 ? 'wajib' : 'opsional' }}{{ $group->max_select > 1 ? ', maks. '.$group->max_select : '' }}</small></legend>
                        @foreach ($group->options as $option)
                            <label @class(['is-unavailable' => ! $option->is_available])>
                                @if ($group->max_select === 1)
                                    <input type="radio" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                @else
                                    <input type="checkbox" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                @endif
                                <span>{{ $option->name }}</span>
                                <span class="member-muted">{{ \App\Support\Money::isPositive($option->price_delta) ? '+'.$money($option->price_delta) : '' }}{{ $option->is_available ? '' : ' (habis)' }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach

                <label class="member-field"><span>Jumlah</span><input type="number" min="1" max="50" wire:model="pickQty" inputmode="numeric"></label>
                <label class="member-field"><span>Catatan</span><input type="text" maxlength="255" wire:model="pickNotes" placeholder="mis. less sugar"></label>

                @if ($pickError)
                    <p class="site-error" role="alert">{{ $pickError }}</p>
                @endif

                <div class="self-order-sheet-actions">
                    <button type="button" class="site-btn site-btn-outline" wire:click="closePicker">Batal</button>
                    <button type="submit" class="site-btn site-btn-primary">Tambah</button>
                </div>
            </form>
        </div>
    @endif
</div>
