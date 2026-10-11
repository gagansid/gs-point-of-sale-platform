{{-- Riwayat poin member (ADR 0013): 100 mutasi terbaru --}}
@php($tz = \App\Support\CurrentOutlet::timezone())
<div class="pos-dash-table-wrap">
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">Saldo: <strong class="text-gray-950 dark:text-white">{{ number_format($member->points_balance, 0, ',', '.') }} poin</strong></p>
    <table class="pos-dash-table">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th class="text-right">Poin</th>
                <th class="text-right">Saldo</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr wire:key="pt-{{ $entry->id }}">
                    <td class="whitespace-nowrap">{{ $entry->created_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }}</td>
                    <td>{{ $entry->type->getLabel() }}</td>
                    <td class="text-right tabular-nums {{ $entry->points < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points, 0, ',', '.') }}</td>
                    <td class="text-right tabular-nums">{{ number_format($entry->balance_after, 0, ',', '.') }}</td>
                    <td class="text-gray-600 dark:text-gray-400">
                        {{ $entry->reason }}{{ $entry->user ? ' · '.$entry->user->name : '' }}
                        @if ($entry->expires_at && $entry->remaining > 0)
                            · berlaku s/d {{ $entry->expires_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-gray-500">Belum ada mutasi poin</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
