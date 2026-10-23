{{--
    Halaman pesan dari meja (ADR 0014, tampilan ADR 0027 §6): Menu → Keranjang → Pesananku lewat navigasi bawah.
    Mode menu Kotak/Baris disimpan di localStorage perangkat. Semua teks pengguna di-escape.
--}}
@php
    $money = fn (string $v): string => \App\Support\Money::format($v);
    $quote = $this->quote;
    $count = (int) array_sum(array_column($cart, 'qty'));
    $member = $this->member();
    $orderCount = $this->myOrders->count();
    $categoryNames = $this->catalog['categories']->pluck('name', 'id')->all();
@endphp
<div x-data="{ setLayout(value) { document.documentElement.dataset.soView = value; try { localStorage.setItem('gspos.so.view', value) } catch (e) {} } }">
    <main @class(['so-main', 'has-cartbar' => $view === 'menu' && $count > 0])>
        @if ($error)
            <div class="so-alert is-danger" role="alert">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true']) <span>{{ $error }}</span></div>
        @endif

        @if ($view === 'menu')
            @if ($context->setting->welcome_text)
                <p class="so-secondary">{{ $context->setting->welcome_text }}</p>
            @endif

            @if ($this->catalog['categories']->count() > 1)
                <nav class="so-cats" aria-label="Kategori">
                    <div class="so-cats-scroll">
                        <button type="button" class="so-tab {{ $category === '' ? 'is-active' : '' }}" wire:click="$set('category', '')">Semua</button>
                        @foreach ($this->catalog['categories'] as $cat)
                            <button type="button" wire:key="c-{{ $cat->id }}" class="so-tab {{ $category === $cat->id ? 'is-active' : '' }}" wire:click="$set('category', '{{ $cat->id }}')">{{ $cat->name }}</button>
                        @endforeach
                    </div>
                </nav>
            @endif

            <section class="so-menu-section" aria-label="Menu">
                <div class="so-menu-head">
                    <h1 class="so-card-title">Menu</h1>
                    <div class="so-segment" role="group" aria-label="Tampilan menu">
                        <button type="button" data-view="grid" x-on:click="setLayout('grid')" aria-label="Tampilan kotak">@svg('heroicon-o-squares-2x2', ['aria-hidden' => 'true'])</button>
                        <button type="button" data-view="list" x-on:click="setLayout('list')" aria-label="Tampilan baris">@svg('heroicon-o-bars-3', ['aria-hidden' => 'true'])</button>
                    </div>
                </div>

                @if ($this->visibleProducts()->isEmpty())
                    <div class="so-card">
                        <div class="so-empty">
                            <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-book-open')</span>
                            <p class="so-empty-title">Menu belum tersedia</p>
                            <p>Silakan pesan langsung di kasir.</p>
                        </div>
                    </div>
                @else
                    <ul class="so-menu">
                        @foreach ($this->visibleProducts() as $product)
                            <li wire:key="p-{{ $product->id }}">
                                <article @class(['so-product', 'is-unavailable' => ! $product->is_available])>
                                    <span class="so-product-media">
                                        @if ($product->imageUrl())
                                            <img src="{{ $product->imageUrl() }}" alt="" loading="lazy" width="160" height="160">
                                        @else
                                            @svg('heroicon-o-photo', ['aria-hidden' => 'true'])
                                        @endif
                                    </span>
                                    <span class="so-product-body">
                                        <span class="so-product-name">{{ $product->name }}</span>
                                        @if ($categoryNames[$product->category_id] ?? null)
                                            <span class="so-product-desc">{{ $categoryNames[$product->category_id] }}</span>
                                        @endif
                                    </span>
                                    <span class="so-product-foot">
                                        <span class="so-product-price">{{ $money($product->price) }}</span>
                                        @if ($product->is_available)
                                            <span class="so-icon-btn is-sm is-primary" aria-hidden="true">@svg('heroicon-o-plus')</span>
                                        @else
                                            <span class="so-badge is-danger is-soldout">Habis</span>
                                        @endif
                                    </span>
                                    <button type="button" class="so-product-open" wire:click="addProduct('{{ $product->id }}')" @disabled(! $product->is_available)
                                        aria-label="{{ $product->is_available ? 'Tambah '.$product->name.', '.$money($product->price) : $product->name.' habis' }}"></button>
                                </article>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @elseif ($view === 'cart')
            <section class="so-card">
                <div class="so-card-head">
                    <h1 class="so-card-title">Keranjang</h1>
                    <span class="so-badge">{{ $context->table->name }}</span>
                </div>

                @if ($cart === [])
                    <div class="so-empty">
                        <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-shopping-bag')</span>
                        <p class="so-empty-title">Keranjang masih kosong</p>
                        <p>Pilih menu dulu, lalu kembali ke sini untuk membayar.</p>
                        <div class="so-actions"><button type="button" class="so-btn is-primary" wire:click="show('menu')">Lihat menu</button></div>
                    </div>
                @else
                    <ul class="so-lines">
                        @foreach ($cart as $i => $line)
                            <li class="so-line" wire:key="l-{{ $i }}">
                                <div>
                                    <p class="so-line-name">{{ $line['name'] }}</p>
                                    @if ($line['option_names'] !== [])
                                        <p class="so-line-meta">{{ implode(', ', $line['option_names']) }}</p>
                                    @endif
                                    @if ($line['notes'])
                                        <p class="so-line-note">“{{ $line['notes'] }}”</p>
                                    @endif
                                </div>
                                <div class="so-line-actions">
                                    <span></span>
                                    <span class="so-qty">
                                        <button type="button" wire:click="decrement({{ $i }})" aria-label="Kurangi {{ $line['name'] }}">@svg($line['qty'] > 1 ? 'heroicon-o-minus' : 'heroicon-o-trash', ['aria-hidden' => 'true'])</button>
                                        <span aria-live="polite">{{ $line['qty'] }}</span>
                                        <button type="button" wire:click="increment({{ $i }})" aria-label="Tambah {{ $line['name'] }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($cart !== [])
                <section class="so-card">
                    <h2 class="so-card-title">Ringkasan</h2>
                    @if ($quote['error'])
                        <p class="so-error" role="alert">{{ $quote['error'] }}</p>
                    @elseif ($quote['totals'])
                        @php($t = $quote['totals'])
                        <dl class="so-totals">
                            <dt>Subtotal</dt><dd>{{ $money($t->subtotal) }}</dd>
                            @if (\App\Support\Money::isPositive($t->voucherDiscount))
                                <dt>Voucher</dt><dd class="is-discount">−{{ $money($t->voucherDiscount) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->memberDiscount))
                                <dt>Diskon member</dt><dd class="is-discount">−{{ $money($t->memberDiscount) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->serviceTotal))
                                <dt>Service</dt><dd>{{ $money($t->serviceTotal) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->taxTotal))
                                <dt>Pajak</dt><dd>{{ $money($t->taxTotal) }}</dd>
                            @endif
                            <dt class="is-grand">Total</dt><dd class="is-grand">{{ $money($t->grandTotal) }}</dd>
                        </dl>
                    @endif

                    <form class="so-form" wire:submit="submit">
                        <label class="so-field">
                            <span class="so-label">Nama pemesan</span>
                            <input class="so-input" type="text" wire:model="customerName" maxlength="100" required autocomplete="name">
                        </label>
                        <label class="so-field">
                            <span class="so-label">Catatan untuk outlet <small>(opsional)</small></span>
                            <input class="so-input" type="text" wire:model="notes" maxlength="255">
                        </label>
                        <label class="so-field">
                            <span class="so-label">Kode voucher <small>(opsional)</small></span>
                            <input class="so-input" type="text" wire:model.blur="voucherCode" maxlength="30" autocomplete="off">
                        </label>
                        @if ($member)
                            <p class="so-muted">Masuk sebagai member <strong>{{ $member->name }}</strong>: poin & diskon member ikut dihitung.</p>
                        @else
                            <p class="so-muted"><a href="{{ route('self-order.member', ['token' => $context->table->qr_token]) }}">Masuk sebagai member</a> untuk dapat poin & diskon.</p>
                        @endif
                        <button type="submit" class="so-btn is-primary is-block" wire:loading.attr="disabled">Kirim pesanan</button>
                        <p class="so-muted">Kasir memeriksa pesanan dulu, setelah itu kamu bisa membayar.</p>
                    </form>
                </section>
            @endif
        @else
            <section class="so-orders" wire:poll.5s aria-live="polite">
                @forelse ($this->myOrders as $selfOrder)
                    @php($status = $statusLabel($selfOrder))
                    <article class="so-card" wire:key="s-{{ $selfOrder->id }}">
                        <div class="so-card-head">
                            <h2 class="so-card-title">{{ $selfOrder->order?->order_number ?? 'Pesanan '.$selfOrder->created_at->setTimezone($context->outlet->timezone)->format('H.i') }}</h2>
                            <span class="so-badge is-{{ $status['tone'] === 'muted' ? 'gray' : $status['tone'] }}">{{ $status['label'] }}</span>
                        </div>
                        <p class="so-secondary so-num">{{ $selfOrder->itemCount() }} item · {{ $money($selfOrder->order->grand_total ?? $selfOrder->estimated_total) }}@if ($selfOrder->order && \App\Support\Money::isPositive($selfOrder->order->paid_total) && $selfOrder->order->status === \App\Enums\OrderStatus::AwaitingPayment) · sisa {{ $money(\App\Support\Money::sub($selfOrder->order->grand_total, $selfOrder->order->paid_total)) }}@endif</p>
                        @if ($status['note'] !== '')
                            <p class="so-muted">{{ $status['note'] }}</p>
                        @endif
                        @if ($selfOrder->order?->status === \App\Enums\OrderStatus::AwaitingPayment)
                            <div class="so-actions is-stack">
                                @if (($points = $this->pointsFor($selfOrder)) > 0)
                                    <button type="button" class="so-btn is-outline is-block" wire:click="payWithPoints('{{ $selfOrder->id }}')" wire:loading.attr="disabled">
                                        Pakai {{ number_format($points, 0, ',', '.') }} poin ({{ $money(\App\Support\Money::mul((string) \App\Models\MemberSetting::current()->redeem_value, $points)) }})
                                    </button>
                                @endif
                                @if ($this->onlineReady)
                                    <button type="button" class="so-btn is-primary is-block" wire:click="payOnline('{{ $selfOrder->id }}')" wire:loading.attr="disabled">Bayar online (QRIS / e-wallet)</button>
                                @endif
                                @if ($context->setting->allow_pay_at_cashier || ! $this->onlineReady)
                                    <p class="so-muted">{{ $this->onlineReady ? 'Atau bayar' : 'Bayar' }} di kasir dengan menyebut nomor pesanan.</p>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="so-card">
                        <div class="so-empty">
                            <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-clipboard-document-list')</span>
                            <p class="so-empty-title">Belum ada pesanan</p>
                            <p>Pesanan dari HP ini akan muncul di sini beserta statusnya.</p>
                            <div class="so-actions"><button type="button" class="so-btn is-primary" wire:click="show('menu')">Lihat menu</button></div>
                        </div>
                    </div>
                @endforelse
            </section>
        @endif

        <p class="so-powered">gs.POS · v{{ config('app.version') }}</p>
    </main>

    {{-- Ringkasan keranjang, menempel di atas navigasi bawah --}}
    @if ($view === 'menu' && $count > 0)
        <div class="so-cartbar">
            <div class="so-cartbar-inner">
                <div class="so-cartbar-info">
                    <span class="so-muted">{{ $count }} item</span>
                    <strong>{{ $quote['totals'] ? $money($quote['totals']->grandTotal) : '—' }}</strong>
                </div>
                <button type="button" class="so-btn is-primary" wire:click="show('cart')">Lihat keranjang @svg('heroicon-o-arrow-right', ['aria-hidden' => 'true'])</button>
            </div>
        </div>
    @endif

    <nav class="so-tabbar" aria-label="Langkah pesanan">
        <div class="so-tabbar-inner">
            @foreach (['menu' => ['Menu', 'heroicon-o-book-open', 0], 'cart' => ['Keranjang', 'heroicon-o-shopping-bag', $count], 'status' => ['Pesananku', 'heroicon-o-clipboard-document-list', $orderCount]] as $key => [$label, $icon, $badge])
                <button type="button" wire:click="show('{{ $key }}')" @class(['is-active' => $view === $key]) @if ($view === $key) aria-current="page" @endif>
                    <span class="so-tabbar-icon">
                        @svg($icon, ['aria-hidden' => 'true'])
                        @if ($badge > 0)
                            <span class="so-tabbar-count">{{ $badge > 99 ? '99+' : $badge }}</span>
                        @endif
                    </span>
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </nav>

    {{-- Dialog opsi (bottom sheet) --}}
    @if ($pickProductId)
        @php($pickProduct = $this->catalog['products']->firstWhere('id', $pickProductId))
        <div class="so-sheet" role="dialog" aria-modal="true" aria-labelledby="pick-title" x-on:keydown.escape.window="$wire.closePicker()">
            <button type="button" class="so-sheet-backdrop" wire:click="closePicker" aria-label="Tutup"></button>
            <form class="so-sheet-panel" wire:submit="savePick">
                <span class="so-sheet-grip" aria-hidden="true"></span>
                <div class="so-sheet-head">
                    <div class="so-sheet-hero">
                        <span class="so-product-media">
                            @if ($pickProduct?->imageUrl())
                                <img src="{{ $pickProduct->imageUrl() }}" alt="">
                            @else
                                @svg('heroicon-o-photo', ['aria-hidden' => 'true'])
                            @endif
                        </span>
                        <div>
                            <h2 id="pick-title" class="so-card-title">{{ $pickProduct?->name }}</h2>
                            <p class="so-secondary so-num">{{ $pickProduct ? $money($pickProduct->price) : '' }}</p>
                        </div>
                    </div>
                    <button type="button" class="so-icon-btn is-plain" wire:click="closePicker" aria-label="Tutup">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                </div>

                <div class="so-sheet-body">
                    @foreach ($this->pickerGroups() as $group)
                        <fieldset class="so-options" wire:key="g-{{ $group->id }}">
                            <legend>{{ $group->name }} <span class="so-badge">{{ $group->min_select > 0 ? 'Pilih '.$group->min_select : 'Opsional' }}{{ $group->max_select > 1 ? ', maks. '.$group->max_select : '' }}</span></legend>
                            @foreach ($group->options as $option)
                                <label @class(['so-option', 'is-unavailable' => ! $option->is_available])>
                                    @if ($group->max_select === 1)
                                        <input type="radio" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                    @else
                                        <input type="checkbox" wire:model="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                    @endif
                                    <span>{{ $option->name }}</span>
                                    <span class="so-option-price">{{ $option->is_available ? (\App\Support\Money::isPositive($option->price_delta) ? '+'.$money($option->price_delta) : '') : 'Habis' }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endforeach

                    <label class="so-field">
                        <span class="so-label">Catatan <small>(opsional)</small></span>
                        <input class="so-input" type="text" maxlength="255" wire:model="pickNotes" placeholder="mis. less sugar">
                    </label>

                    @if ($pickError)
                        <p class="so-error" role="alert">{{ $pickError }}</p>
                    @endif
                </div>

                <div class="so-sheet-foot">
                    <span class="so-qty">
                        <button type="button" wire:click="$set('pickQty', {{ max(1, $pickQty - 1) }})" @disabled($pickQty <= 1) aria-label="Kurangi jumlah">@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                        <span aria-live="polite">{{ $pickQty }}</span>
                        <button type="button" wire:click="$set('pickQty', {{ min(50, $pickQty + 1) }})" @disabled($pickQty >= 50) aria-label="Tambah jumlah">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                    </span>
                    <button type="submit" class="so-btn is-primary">Tambah</button>
                </div>
            </form>
        </div>
    @endif
</div>
