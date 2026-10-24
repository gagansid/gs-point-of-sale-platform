{{--
    Pengaturan → Langganan (ADR 0024/0025, Q123, Q141): kartu paket (hero), tagihan menunggu (bayar QRIS / VA / transfer
    manual + bukti), pilih paket (kartu harga), add-on, pemakaian kuota, riwayat tagihan (detail per tagihan).
--}}
@php
    $tenant = $this->tenant();
    $pendingInvoices = $this->pendingInvoices();
    $money = fn (string $v): string => \App\Support\Money::format($v);
    $tz = \App\Support\CurrentOutlet::timezone();
    $date = fn ($d): string => $d ? $d->setTimezone($tz)->locale('id')->translatedFormat('j M Y') : '—';
    $endsAt = $tenant->subscription_ends_at;
    $daysLeft = $endsAt ? (int) max(0, ceil(now()->diffInHours($endsAt, false) / 24)) : null;
    $cycleDays = match ($tenant->billing_cycle) {
        \App\Enums\BillingCycle::Yearly => 365,
        \App\Enums\BillingCycle::Monthly => 30,
        default => max(1, app(\App\Support\SystemSettings::class)->trialDays()),
    };
    $progress = $daysLeft !== null ? (int) min(100, max(0, round($daysLeft / $cycleDays * 100))) : null;
    $tone = match (true) {
        $daysLeft === null => 'neutral',
        $daysLeft <= 3 => 'danger',
        $daysLeft <= 7 => 'warning',
        default => 'success',
    };
    $features = $tenant->plan
        ? collect(\App\Models\Feature::options())->only(\App\Support\Features::enabledKeys($tenant))
        : collect();
    $owned = $tenant->activeAddons();
@endphp
<x-filament-panels::page>
    <div class="gs-sub-grid">
        {{-- Paket saat ini: kartu hero warna merek, sisa masa aktif & aksi utama --}}
        <section class="gs-plan-card is-{{ $tone }}">
            <div class="gs-plan-card-top">
                <div class="min-w-0">
                    <div class="gs-plan-eyebrow">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles" />
                        Paket saat ini
                        <span class="gs-plan-status">{{ $tenant->status->getLabel() }}{{ $tenant->isReadOnly() ? ' · hanya-baca' : '' }}</span>
                    </div>
                    <div class="gs-plan-name">{{ $tenant->plan?->name ?? 'Semua fitur (tanpa paket)' }}</div>
                    <div class="gs-plan-meta">
                        {{ $tenant->billing_cycle?->getLabel() ?? 'Tanpa siklus' }}
                        @if ($tenant->plan && $tenant->billing_cycle)
                            · {{ $money($tenant->plan->price($tenant->billing_cycle)) }}/{{ $tenant->billing_cycle === \App\Enums\BillingCycle::Yearly ? 'tahun' : 'bulan' }}
                        @endif
                    </div>
                </div>
                <div class="gs-plan-days">
                    <strong>{{ $daysLeft === null ? '∞' : number_format($daysLeft, 0, ',', '.') }}</strong>
                    <span>{{ $daysLeft === null ? 'tanpa batas' : ($daysLeft === 0 ? 'berakhir hari ini' : 'hari lagi') }}</span>
                </div>
            </div>

            <div class="gs-plan-period">
                <div class="gs-plan-period-row">
                    <span>Aktif sampai <strong>{{ $date($endsAt) }}</strong></span>
                    @if ($progress !== null)<span>{{ $progress }}% tersisa</span>@endif
                </div>
                @if ($progress !== null)
                    <div class="gs-plan-bar"><span style="width: {{ $progress }}%"></span></div>
                @endif
            </div>

            <div class="gs-plan-actions">
                @if ($this->plans()->isNotEmpty())
                    {{ $this->createInvoiceAction }}
                @endif
                @if ($this->canBuyAddons())
                    {{ $this->addAddonAction }}
                @endif
            </div>

            @if ($tenant->nextPlan && $tenant->plan_changes_at)
                <div class="gs-callout is-info">
                    <strong>Paket berikutnya: {{ $tenant->nextPlan->name }} · {{ $tenant->next_billing_cycle?->getLabel() }}</strong>, mulai {{ $date($tenant->plan_changes_at) }}.
                </div>
            @endif

            @if ($features->isNotEmpty() || $owned->isNotEmpty())
                <div class="gs-plan-features">
                    <div class="gs-plan-label">Fitur termasuk</div>
                    <div class="gs-chips">
                        @foreach ($features as $feature)
                            <span class="gs-chip"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" class="gs-chip-icon" />{{ $feature }}</span>
                        @endforeach
                        @foreach ($owned as $row)
                            <span class="gs-chip is-addon" wire:key="chip-{{ $row->id }}">
                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPuzzlePiece" class="gs-chip-icon" />{{ $row->addon->name }}{{ $row->addon->isQuota() ? ' ×'.$row->quantity : '' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @elseif ($tenant->plan)
                <p class="gs-muted">Fitur dasar kasir & laporan</p>
            @endif

            @if ($this->plans()->isEmpty())
                <p class="gs-muted">Belum ada paket yang bisa dipilih. Hubungi tim gs.POS untuk perpanjangan.</p>
            @endif
        </section>

        {{-- Tagihan menunggu --}}
        <x-filament::section :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentText" heading="Tagihan menunggu" class="gs-sub-pending">
            @forelse ($pendingInvoices as $pending)
                <div wire:key="pending-{{ $pending->id }}" class="gs-due-card">
                    <div class="gs-due-head">
                        <span class="gs-due-number">{{ $pending->number }}</span>
                        <x-filament::badge :color="$pending->statusColor()">{{ $pending->statusLabel() }}</x-filament::badge>
                    </div>
                    <div class="gs-due-amount">{{ $money((string) $pending->amount) }}</div>
                    <div class="gs-due-desc">{{ $pending->description() }}</div>
                    <div class="gs-due-date">
                        Jatuh tempo {{ $date($pending->due_at) }}
                        @if ($pending->isPayable())
                            · {{ max(0, (int) ceil(now()->diffInHours($pending->due_at, false) / 24)) }} hari lagi
                        @endif
                    </div>
                    @if ($pending->memo)
                        <div class="gs-due-memo">{{ $pending->memo }}</div>
                    @endif

                    @if ($pending->awaitingVerification())
                        <div class="gs-callout is-info">Bukti transfer dikirim {{ $date($pending->proof_submitted_at) }}. Tim gs.POS sedang memverifikasi.</div>
                    @elseif ($pending->proof_rejected_reason)
                        <div class="gs-callout is-danger"><strong>Bukti ditolak:</strong> {{ $pending->proof_rejected_reason }}. Silakan kirim ulang.</div>
                    @endif

                    <div class="gs-due-actions">
                        @if ($pending->isPayable())
                            {{ ($this->payInvoiceAction)(['id' => $pending->id]) }}
                        @endif
                        {{-- Ikon 32px bergaris (gs-row-actions): sejajar tinggi tombol Bayar --}}
                        <div class="gs-row-actions is-outlined">
                            {{ ($this->invoiceDetailAction)(['id' => $pending->id]) }}
                            {{ ($this->downloadPdfAction)(['id' => $pending->id]) }}
                        </div>
                    </div>
                </div>
            @empty
                <div class="gs-empty-mini">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheckBadge" class="gs-empty-mini-icon" />
                    <div class="gs-empty-mini-title">Tidak ada tagihan</div>
                    <div class="gs-empty-mini-text">Buat tagihan untuk memperpanjang atau ganti paket.</div>
                </div>
            @endforelse
        </x-filament::section>
    </div>

    {{-- Pilih paket: kartu harga berdampingan, saklar Bulanan/Tahunan; klik = Buat tagihan untuk paket itu --}}
    @php($cards = $this->planCards())
    @if ($cards !== [])
        <x-filament::section :icon="\Filament\Support\Icons\Heroicon::OutlinedSquares2x2" heading="Pilih paket" description="Upgrade langsung aktif (bayar selisih sisa masa aktif); perpanjangan dimulai dari akhir masa aktif sekarang">
            <x-slot name="afterHeader">
                <div class="gs-cycle-switch" role="tablist" aria-label="Siklus harga">
                    @foreach (\App\Enums\BillingCycle::cases() as $cycleOption)
                        <button type="button" role="tab" aria-selected="{{ $pricingCycle === $cycleOption->value ? 'true' : 'false' }}"
                            @class(['is-active' => $pricingCycle === $cycleOption->value]) wire:click="setPricingCycle('{{ $cycleOption->value }}')">
                            {{ $cycleOption->getLabel() }}
                        </button>
                    @endforeach
                </div>
            </x-slot>

            <div class="gs-price-grid">
                @foreach ($cards as $card)
                    <article wire:key="plan-{{ $card['id'] }}" @class(['gs-price-card', 'is-current' => $card['current']])>
                        <div class="gs-price-head">
                            <h3>{{ $card['name'] }}</h3>
                            @if ($card['current'])
                                <span class="gs-price-tag">Paket saat ini</span>
                            @elseif ($pricingCycle === 'yearly' && $card['saving'])
                                <span class="gs-price-tag is-saving">Hemat {{ $card['saving'] }}%</span>
                            @endif
                        </div>
                        @if ($card['description'])
                            <p class="gs-price-desc">{{ $card['description'] }}</p>
                        @endif
                        <div class="gs-price-amount"><strong>{{ $card['price'] }}</strong><span>{{ $card['per'] }}</span></div>
                        <div class="gs-price-sub">{{ $card['monthly_equivalent'] ? 'setara '.$card['monthly_equivalent'] : ' ' }}</div>
                        <ul class="gs-price-list">
                            @forelse ($card['limits'] as $limit)
                                <li><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" />{{ $limit }}</li>
                            @empty
                                <li><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" />Tanpa batas kuota</li>
                            @endforelse
                            @foreach ($card['features'] as $featureName)
                                <li class="is-feature"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" />{{ $featureName }}</li>
                            @endforeach
                        </ul>
                        <x-filament::button :color="$card['current'] ? 'gray' : 'primary'" class="gs-price-cta"
                            wire:click="mountAction('createInvoice', { plan: '{{ $card['id'] }}', cycle: '{{ $pricingCycle }}' })">
                            {{ $card['cta'] }}
                        </x-filament::button>
                    </article>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Add-on (ADR 0025 C2) --}}
    @if ($owned->isNotEmpty() || $this->canBuyAddons())
        <x-filament::section :icon="\Filament\Support\Icons\Heroicon::OutlinedPuzzlePiece" heading="Add-on" description="Fitur atau kuota tambahan di atas paket; masa aktif mengikuti paket">
            @if ($owned->isEmpty())
                <p class="gs-muted !mt-0">Belum ada add-on aktif. Klik Tambah add-on di kartu paket untuk menambah fitur atau kuota.</p>
            @else
            <div class="pos-dash-table-wrap">
                <table class="pos-dash-table gs-sub-table">
                    <thead><tr><th>Add-on</th><th>Manfaat</th><th class="text-right">Harga per siklus</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($owned as $row)
                            <tr wire:key="addon-{{ $row->id }}">
                                <td class="font-medium">{{ $row->addon->name }}{{ $row->addon->isQuota() ? ' ×'.$row->quantity : '' }}</td>
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
        </x-filament::section>
    @endif

    {{-- Pemakaian vs batas paket (ADR 0025) --}}
    <x-filament::section :icon="\Filament\Support\Icons\Heroicon::OutlinedChartBar" heading="Pemakaian kuota" description="Data yang sudah ada tetap berjalan walau melebihi batas; menambah data baru ditolak sampai di bawah batas">
        @include('filament.shared.quota-usage', ['rows' => \App\Support\Quotas::usage($tenant)])
    </x-filament::section>

    {{-- Riwayat tagihan: tabel Filament standar (cari, filter, urut, paginasi) --}}
    {{ $this->table }}
</x-filament-panels::page>
