{{-- Riwayat kasbon member (ADR 0023): 100 mutasi terbaru --}}
@php($tz = \App\Support\CurrentOutlet::timezone())
@php($money = fn (string $v): string => \App\Support\Money::format($v))
<div class="pos-dash-table-wrap">
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
        Kasbon: <strong class="text-gray-950 dark:text-white">{{ $money($member->receivable_balance) }}</strong>
        · batas {{ $member->effectiveCreditLimit() !== null ? $money($member->effectiveCreditLimit()) : 'belum diatur' }}
    </p>
    <table class="pos-dash-table">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th class="text-right">Nominal</th>
                <th class="text-right">Saldo</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr wire:key="rc-{{ $entry->id }}">
                    <td class="whitespace-nowrap">{{ $entry->created_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }}</td>
                    <td>{{ $entry->type->getLabel() }}</td>
                    <td class="text-right tabular-nums {{ \App\Support\Money::isPositive($entry->amount) ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">{{ \App\Support\Money::isPositive($entry->amount) ? '+' : '' }}{{ $money($entry->amount) }}</td>
                    <td class="text-right tabular-nums">{{ $money($entry->balance_after) }}</td>
                    <td class="text-gray-600 dark:text-gray-400">{{ collect([$entry->note, $entry->paymentMethod?->name, $entry->user?->name])->filter()->implode(' · ') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-gray-500">Belum ada kasbon</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
