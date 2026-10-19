{{-- Pengaturan → Langganan (ADR 0024): paket & masa aktif, tagihan menunggu, riwayat. --}}
@php
    $tenant = $this->tenant();
    $pendingInvoices = $this->pendingInvoices();
    $money = fn (string $v): string => \App\Support\Money::format($v);
    $tz = \App\Support\CurrentOutlet::timezone();
    $date = fn ($d): string => $d ? $d->setTimezone($tz)->locale('id')->translatedFormat('j M Y') : '—';
@endphp
<x-filament-panels::page>
    <div class="grid gap-4 lg:grid-cols-3">
        <x-filament::section heading="Paket saat ini" class="lg:col-span-2">
            <dl class="gs-nota-meta">
                <div><dt>Paket</dt><dd>{{ $tenant->plan?->name ?? 'Semua fitur (tanpa paket)' }}</dd></div>
                <div><dt>Siklus</dt><dd>{{ $tenant->billing_cycle?->getLabel() ?? '—' }}</dd></div>
                <div><dt>Status</dt><dd>{{ $tenant->status->getLabel() }}{{ $tenant->isReadOnly() ? ' · hanya-baca' : '' }}</dd></div>
                <div><dt>Aktif sampai</dt><dd>{{ $date($tenant->subscription_ends_at) }}</dd></div>
                @if ($tenant->nextPlan && $tenant->plan_changes_at)
                    <div class="is-wide"><dt>Paket berikutnya</dt><dd>{{ $tenant->nextPlan->name }} · {{ $tenant->next_billing_cycle?->getLabel() }}, mulai {{ $date($tenant->plan_changes_at) }}</dd></div>
                @endif
                @if ($tenant->activeAddons()->isNotEmpty())
                    <div class="is-wide"><dt>Add-on</dt><dd>{{ $tenant->activeAddons()->map(fn ($a) => $a->addon->name.($a->addon->isQuota() ? ' ×'.$a->quantity : ''))->implode(' · ') }}</dd></div>
                @endif
                @if ($tenant->plan)
                    <div class="is-wide"><dt>Fitur</dt><dd>{{ collect(\App\Models\Feature::options())->only(\App\Support\Features::enabledKeys($tenant))->implode(' · ') ?: 'Fitur dasar kasir & laporan' }}</dd></div>
                @endif
            </dl>
            @if ($this->plans()->isNotEmpty())
                <div class="mt-4 flex gap-2">{{ $this->createInvoiceAction }}</div>
            @else
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Belum ada paket yang bisa dipilih. Hubungi tim gs.POS untuk perpanjangan.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Tagihan menunggu">
            @forelse ($pendingInvoices as $pending)
                <div wire:key="pending-{{ $pending->id }}" @class(['mt-4 border-t border-gray-200 pt-4 dark:border-white/10' => ! $loop->first])>
                    <dl class="gs-nota-meta gs-nota-meta--single">
                        <div><dt>Nomor</dt><dd>{{ $pending->number }}</dd></div>
                        <div><dt>Rincian</dt><dd>{{ $pending->description() }}</dd></div>
                        @if ($pending->kind === \App\Enums\InvoiceKind::Manual)
                            <div><dt>Item</dt><dd>{{ $pending->items->map(fn ($i) => $i->name.($i->quantity > 1 ? ' ×'.$i->quantity : ''))->implode(' · ') }}</dd></div>
                        @endif
                        <div><dt>Total</dt><dd>{{ $money($pending->amount) }}</dd></div>
                        <div><dt>Jatuh tempo</dt><dd>{{ $date($pending->due_at) }}</dd></div>
                        @if ($pending->memo)
                            <div><dt>Catatan</dt><dd class="whitespace-pre-line">{{ $pending->memo }}</dd></div>
                        @endif
                    </dl>
                    <div class="mt-4 flex flex-col gap-2">
                        @if ($this->onlinePaymentAvailable())
                            {{ ($this->payOnlineAction)(['id' => $pending->id]) }}
                        @endif
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $this->onlinePaymentAvailable() ? 'Atau transfer manual: ' : '' }}{{ config('pos.billing.bank_info') }} Cantumkan nomor {{ $pending->number }}.
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-400">Tidak ada tagihan. Buat tagihan untuk memperpanjang atau ganti paket.</p>
            @endforelse
        </x-filament::section>
    </div>

    {{-- Add-on (ADR 0025 C2) --}}
    @php($owned = $tenant->activeAddons())
    @if ($owned->isNotEmpty() || $this->canBuyAddons())
        <x-filament::section heading="Add-on" description="Fitur atau kuota tambahan di atas paket; masa aktif mengikuti paket">
            @if ($owned->isNotEmpty())
                <div class="pos-dash-table-wrap">
                    <table class="pos-dash-table">
                        <thead><tr><th>Add-on</th><th>Manfaat</th><th class="text-right">Harga per siklus</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($owned as $row)
                                <tr wire:key="addon-{{ $row->id }}">
                                    <td>{{ $row->addon->name }}{{ $row->addon->isQuota() ? ' ×'.$row->quantity : '' }}</td>
                                    <td>{{ $row->addon->benefit() }}</td>
                                    <td class="text-right tabular-nums">{{ $tenant->billing_cycle ? $money(\App\Support\Money::mul($row->addon->price($tenant->billing_cycle), $row->quantity)) : '—' }}</td>
                                    <td>
                                        @if ($row->status === \App\Models\TenantAddon::CANCELLING)
                                            <x-filament::badge color="warning">Berhenti {{ $date($row->cancel_at) }}</x-filament::badge>
                                        @else
                                            <x-filament::badge color="success">Aktif</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($row->status === \App\Models\TenantAddon::CANCELLING)
                                            {{ ($this->resumeAddonAction)(['id' => $row->id]) }}
                                        @else
                                            {{ ($this->stopAddonAction)(['id' => $row->id]) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($this->canBuyAddons())
                <div class="mt-4">{{ $this->addAddonAction }}</div>
            @endif
        </x-filament::section>
    @endif

    {{-- Pemakaian vs batas paket (ADR 0025) --}}
    <x-filament::section heading="Pemakaian kuota" description="Data yang sudah ada tetap berjalan walau melebihi batas; menambah data baru ditolak sampai di bawah batas">
        @include('filament.shared.quota-usage', ['rows' => \App\Support\Quotas::usage($tenant)])
    </x-filament::section>

    <x-filament::section heading="Riwayat tagihan">
        <div class="pos-dash-table-wrap">
            <table class="pos-dash-table">
                <thead><tr><th>Nomor</th><th>Rincian</th><th class="text-right">Total</th><th>Status</th><th>Periode</th></tr></thead>
                <tbody>
                    @forelse ($this->invoices() as $invoice)
                        <tr wire:key="inv-{{ $invoice->id }}">
                            <td>{{ $invoice->number }}</td>
                            <td>{{ $invoice->description() }}</td>
                            <td class="text-right tabular-nums">{{ $money($invoice->amount) }}</td>
                            <td><x-filament::badge :color="$invoice->status->getColor()">{{ $invoice->status->getLabel() }}</x-filament::badge></td>
                            <td>{{ $invoice->period_start ? $date($invoice->period_start).' – '.$date($invoice->period_end) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-gray-500">Belum ada tagihan</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
