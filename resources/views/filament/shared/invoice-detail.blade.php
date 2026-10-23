{{--
    Detail tagihan langganan bergaya dokumen invoice (modal admin Langganan → Tagihan & owner Pengaturan → Langganan):
    kop (logo, nomor, status), penerbit & penerima, ringkasan tanggal, rincian baris, total, alur pembayaran & bukti
    transfer (Q123), catatan. $audience: 'admin' (tampil pembuat & catatan internal) | 'owner'.
--}}
@php
    /** @var \App\Models\SubscriptionInvoice $invoice */
    $audience ??= 'admin';
    $money = fn ($v): string => \App\Support\Money::format((string) $v);
    $tz = $audience === 'owner' ? \App\Support\CurrentOutlet::timezone() : \Filament\Support\Facades\FilamentTimezone::get();
    $date = fn ($d, string $f = 'j M Y'): string => $d ? $d->setTimezone($tz)->locale('id')->translatedFormat($f) : '—';
    $hasDiscount = bccomp((string) $invoice->discount_amount, '0', 2) > 0;
    $hasTax = bccomp((string) $invoice->tax_amount, '0', 2) > 0;
    $tenant = $invoice->tenant ?? \App\Models\Tenant::query()->find($invoice->tenant_id);
    $owner = \App\Models\User::allTenants()->where('tenant_id', $invoice->tenant_id)->where('role', \App\Enums\UserRole::Owner)
        ->where('is_active', true)->orderBy('created_at')->first(['name', 'email']);
    $issuer = (array) config('pos.billing.issuer');
    $proofUrl = $invoice->proof_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($invoice->proof_path)
        ? \Illuminate\Support\Facades\Storage::disk('local')->temporaryUrl($invoice->proof_path, now()->addMinutes(15))
        : null;
    $proofIsPdf = $invoice->proof_path && str_ends_with(strtolower($invoice->proof_path), '.pdf');
    $isPaid = $invoice->status === \App\Enums\InvoiceStatus::Paid;
    $isClosed = in_array($invoice->status, [\App\Enums\InvoiceStatus::Cancelled, \App\Enums\InvoiceStatus::Expired], true);
    $paidVia = match ($invoice->paid_via) {
        'manual' => 'Transfer manual',
        'midtrans' => 'Online · '.($invoice->payment_method?->getLabel() ?? 'Midtrans'),
        default => null,
    };
    // Alur pembayaran: dibuat → (bukti dikirim) → lunas / dibatalkan / kedaluwarsa
    $steps = [['label' => 'Tagihan dibuat', 'at' => $invoice->created_at, 'done' => true, 'note' => $audience === 'admin' ? ($invoice->created_by_admin_id ? 'oleh admin '.($invoice->createdByAdmin?->name ?? '') : 'oleh owner') : null]];
    if ($invoice->proof_submitted_at || $invoice->proof_rejected_at || $invoice->payment_method === \App\Enums\InvoicePaymentMethod::Manual) {
        $steps[] = ['label' => 'Bukti transfer dikirim', 'at' => $invoice->proof_submitted_at ?? $invoice->proof_rejected_at, 'done' => $invoice->proof_path !== null, 'note' => $invoice->proof_sender];
    }
    if ($invoice->proof_rejected_at && ! $invoice->proof_submitted_at && ! $isPaid) {
        $steps[] = ['label' => 'Bukti ditolak', 'at' => $invoice->proof_rejected_at, 'done' => true, 'danger' => true, 'note' => $invoice->proof_rejected_reason];
    }
    $steps[] = match (true) {
        $isPaid => ['label' => 'Lunas', 'at' => $invoice->paid_at, 'done' => true, 'note' => $paidVia],
        $isClosed => ['label' => $invoice->status->getLabel(), 'at' => $invoice->updated_at, 'done' => true, 'danger' => true, 'note' => null],
        $invoice->awaitingVerification() => ['label' => 'Verifikasi tim gs.POS', 'at' => null, 'done' => false, 'current' => true, 'note' => 'Maks. 1×24 jam kerja'],
        default => ['label' => 'Menunggu pembayaran', 'at' => $invoice->due_at, 'done' => false, 'current' => true, 'note' => 'Jatuh tempo'],
    };
@endphp
<div class="gs-inv">
    {{-- Kop --}}
    <div class="gs-inv-head">
        <div class="gs-inv-brand">
            <img src="{{ asset('images/brand/logo-horizontal-color.svg') }}" alt="gs.POS" class="gs-inv-logo dark:hidden">
            <img src="{{ asset('images/brand/logo-horizontal-reversed.svg') }}" alt="gs.POS" class="gs-inv-logo hidden dark:block">
            <div class="gs-inv-issuer">
                <strong>{{ $issuer['name'] ?? 'gs.POS' }}</strong>
                @if (filled($issuer['address'] ?? null))<span>{{ $issuer['address'] }}</span>@endif
                @if (filled($issuer['email'] ?? null))<span>{{ $issuer['email'] }}</span>@endif
            </div>
        </div>
        <div class="gs-inv-title">
            <span class="gs-inv-kicker">Tagihan</span>
            <span class="gs-inv-number">{{ $invoice->number }}</span>
            <x-filament::badge :color="$invoice->statusColor()" size="lg">{{ $invoice->statusLabel() }}</x-filament::badge>
        </div>
    </div>

    {{-- Penerima & ringkasan --}}
    <div class="gs-inv-parties">
        <div>
            <div class="gs-inv-label">Ditagihkan kepada</div>
            <div class="gs-inv-strong">{{ $tenant?->name ?? '—' }}</div>
            @if ($owner)
                <div class="gs-inv-muted">{{ $owner->name }} · {{ $owner->email }}</div>
            @endif
        </div>
        <div class="gs-inv-amount-box">
            <div class="gs-inv-label">{{ $isPaid ? 'Total dibayar' : 'Total tagihan' }}</div>
            <div class="gs-inv-amount">{{ $money($invoice->amount) }}</div>
            @if (! $isPaid && ! $isClosed)
                <div @class(['gs-inv-muted', 'text-danger-600' => $invoice->isOverdue()])>Jatuh tempo {{ $date($invoice->due_at) }}</div>
            @elseif ($isPaid)
                <div class="gs-inv-muted">Lunas {{ $date($invoice->paid_at) }}</div>
            @endif
        </div>
    </div>

    <dl class="gs-inv-facts">
        <div><dt>Tanggal tagihan</dt><dd>{{ $date($invoice->created_at) }}</dd></div>
        <div><dt>Jatuh tempo</dt><dd>{{ $date($invoice->due_at) }}</dd></div>
        <div><dt>Jenis</dt><dd>{{ $invoice->kind->getLabel() }}</dd></div>
        <div><dt>Periode layanan</dt><dd>{{ $invoice->period_start ? $date($invoice->period_start).' – '.$date($invoice->period_end) : ($invoice->plan_name ? 'Mulai saat lunas' : '—') }}</dd></div>
    </dl>

    {{-- Rincian --}}
    <div class="gs-inv-lines">
        <table>
            <thead>
                <tr><th class="w-8">#</th><th>Uraian</th><th class="is-num">Jumlah</th><th class="is-num">Harga satuan</th><th class="is-num">Total</th></tr>
            </thead>
            <tbody>
                @forelse ($invoice->items as $item)
                    <tr wire:key="line-{{ $item->id }}">
                        <td class="gs-inv-muted">{{ $loop->iteration }}</td>
                        <td>
                            <div class="gs-inv-strong">{{ $item->name }}</div>
                            <div class="gs-inv-muted">{{ match ($item->type) { 'plan' => 'Paket'.($invoice->billing_cycle ? ' · '.$invoice->billing_cycle->getLabel() : ''), 'addon' => 'Add-on · aktif saat lunas', default => 'Biaya lain' } }}</div>
                        </td>
                        <td class="is-num">{{ $item->quantity }}</td>
                        <td class="is-num">{{ $money($item->unit_price) }}</td>
                        <td class="is-num gs-inv-strong">{{ $money($item->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="gs-inv-muted text-center">{{ $invoice->title ?? $invoice->description() }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="gs-inv-totals">
            <div><span>Subtotal</span><span>{{ $money($invoice->items->isNotEmpty() ? $invoice->subtotal() : $invoice->amount) }}</span></div>
            @if ($hasDiscount)
                <div><span>Diskon</span><span>−{{ $money($invoice->discount_amount) }}</span></div>
            @endif
            @if ($hasTax)
                <div><span>PPN {{ rtrim(rtrim((string) $invoice->tax_rate, '0'), '.') }}%</span><span>{{ $money($invoice->tax_amount) }}</span></div>
            @endif
            <div class="is-total"><span>Total</span><span>{{ $money($invoice->amount) }}</span></div>
        </div>
    </div>

    {{-- Pembayaran --}}
    <div class="gs-inv-pay">
        <div class="gs-inv-section-title">Status pembayaran</div>
        <ol class="gs-inv-steps">
            @foreach ($steps as $step)
                <li @class(['is-done' => $step['done'] && empty($step['danger']), 'is-danger' => ! empty($step['danger']), 'is-current' => ! empty($step['current'])])>
                    <span class="gs-inv-step-dot"></span>
                    <div>
                        <div class="gs-inv-strong">{{ $step['label'] }}</div>
                        <div class="gs-inv-muted">
                            {{ $step['at'] ? $date($step['at'], 'j M Y, H.i') : '' }}{{ $step['at'] && filled($step['note']) ? ' · ' : '' }}{{ $step['note'] }}
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>

        <dl class="gs-inv-facts is-compact">
            <div><dt>Metode</dt><dd>{{ $invoice->payment_method?->getLabel() ?? ($paidVia ?? 'Belum dipilih') }}</dd></div>
            @if ($invoice->proof_path)
                <div><dt>Pengirim</dt><dd>{{ $invoice->proof_sender ?? '—' }}</dd></div>
                @if ($invoice->proof_note)
                    <div class="is-wide"><dt>Catatan pengirim</dt><dd>{{ $invoice->proof_note }}</dd></div>
                @endif
            @endif
        </dl>

        @if ($proofUrl)
            <a href="{{ $proofUrl }}" target="_blank" rel="noopener" class="gs-proof">
                @if ($proofIsPdf)
                    <span class="gs-proof-file">PDF</span><span>Buka bukti transfer (PDF)</span>
                @else
                    <img src="{{ $proofUrl }}" alt="Bukti transfer {{ $invoice->number }}">
                @endif
            </a>
        @endif
    </div>

    @if ($invoice->memo || ($invoice->notes && $audience === 'admin'))
        <div class="gs-inv-notes">
            @if ($invoice->memo)
                <div><div class="gs-inv-label">Catatan</div><p class="whitespace-pre-line">{{ $invoice->memo }}</p></div>
            @endif
            @if ($invoice->notes && $audience === 'admin')
                <div><div class="gs-inv-label">Catatan pembayaran (internal)</div><p>{{ $invoice->notes }}</p></div>
            @endif
        </div>
    @endif

    <div class="gs-inv-foot">Cantumkan nomor <span class="font-mono">{{ $invoice->number }}</span> pada setiap pembayaran. Terima kasih telah memakai gs.POS.</div>
</div>
