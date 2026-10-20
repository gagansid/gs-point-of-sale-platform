{{-- Tagihan → Detail (modal): identitas, rincian baris, diskon/PPN/total, catatan. --}}
@php
    /** @var \App\Models\SubscriptionInvoice $invoice */
    $money = fn ($v): string => \App\Support\Money::format((string) $v);
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
    $date = fn ($d, string $f = 'j M Y, H.i'): string => $d ? $d->setTimezone($tz)->locale('id')->translatedFormat($f) : '—';
    $hasDiscount = bccomp((string) $invoice->discount_amount, '0', 2) > 0;
    $hasTax = bccomp((string) $invoice->tax_amount, '0', 2) > 0;
@endphp
<div class="gs-invoice-detail">
    <dl class="gs-nota-meta">
        <div><dt>Bisnis</dt><dd>{{ $invoice->tenant?->name ?? '—' }}</dd></div>
        <div><dt>Status</dt><dd><x-filament::badge :color="$invoice->status->getColor()">{{ $invoice->status->getLabel() }}</x-filament::badge></dd></div>
        <div><dt>Jenis</dt><dd>{{ $invoice->kind->getLabel() }}{{ $invoice->title ? ' · '.$invoice->title : '' }}</dd></div>
        <div><dt>Paket</dt><dd>{{ $invoice->plan_name ?? '—' }}{{ $invoice->billing_cycle ? ' · '.$invoice->billing_cycle->getLabel() : '' }}</dd></div>
        <div><dt>Dibuat</dt><dd>{{ $date($invoice->created_at) }} · {{ $invoice->created_by_admin_id ? 'admin '.($invoice->createdByAdmin?->name ?? '') : 'owner' }}</dd></div>
        <div><dt>Jatuh tempo</dt><dd @class(['text-danger-600' => $invoice->isOverdue()])>{{ $date($invoice->due_at, 'j M Y') }}</dd></div>
        <div><dt>Lunas</dt><dd>{{ $date($invoice->paid_at) }}{{ $invoice->paid_via ? ' · '.($invoice->paid_via === 'manual' ? 'transfer manual' : 'online') : '' }}</dd></div>
        <div><dt>Periode</dt><dd>{{ $invoice->period_start ? $date($invoice->period_start, 'j M Y').' – '.$date($invoice->period_end, 'j M Y') : '—' }}</dd></div>
    </dl>

    <table class="gs-detail-table gs-invoice-lines">
        <thead>
            <tr><th>Uraian</th><th class="is-num">Jumlah</th><th class="is-num">Harga satuan</th><th class="is-num">Subtotal</th></tr>
        </thead>
        <tbody>
            @forelse ($invoice->items as $item)
                <tr wire:key="line-{{ $item->id }}">
                    <td>
                        <div class="gs-detail-name">{{ $item->name }}</div>
                        <div class="gs-detail-sub">{{ match ($item->type) { 'plan' => 'Paket', 'addon' => 'Add-on · aktif saat lunas', default => 'Biaya lain' } }}</div>
                    </td>
                    <td class="is-num">{{ $item->quantity }}</td>
                    <td class="is-num">{{ $money($item->unit_price) }}</td>
                    <td class="is-num">{{ $money($item->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="gs-detail-empty">Tanpa rincian</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="gs-invoice-sum">
        @if ($hasDiscount || $hasTax)
            <div class="gs-invoice-sum-row"><span>Subtotal</span><span class="tabular-nums">{{ $money($invoice->subtotal()) }}</span></div>
        @endif
        @if ($hasDiscount)
            <div class="gs-invoice-sum-row"><span>Diskon</span><span class="tabular-nums">−{{ $money($invoice->discount_amount) }}</span></div>
        @endif
        @if ($hasTax)
            <div class="gs-invoice-sum-row"><span>PPN {{ rtrim(rtrim((string) $invoice->tax_rate, '0'), '.') }}%</span><span class="tabular-nums">{{ $money($invoice->tax_amount) }}</span></div>
        @endif
        <div class="gs-invoice-sum-row is-total"><span>Total</span><span class="tabular-nums">{{ $money($invoice->amount) }}</span></div>
    </div>

    @if ($invoice->memo || $invoice->notes)
        <dl class="gs-nota-meta gs-nota-meta--single">
            @if ($invoice->memo)
                <div class="is-wide"><dt>Catatan untuk pelanggan</dt><dd class="whitespace-pre-line">{{ $invoice->memo }}</dd></div>
            @endif
            @if ($invoice->notes)
                <div class="is-wide"><dt>Catatan pembayaran</dt><dd>{{ $invoice->notes }}</dd></div>
            @endif
        </dl>
    @endif
</div>
