{{-- Kartu stempel member (ADR 0013, M6): saldo per program aktif --}}
<div class="pos-dash-table-wrap">
    <table class="pos-dash-table">
        <thead>
            <tr>
                <th>Kartu</th>
                <th class="text-right">Stempel</th>
                <th class="text-right">Gratis tersedia</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cards as $card)
                <tr wire:key="st-{{ $card['id'] }}">
                    <td>{{ $card['name'] }}</td>
                    <td class="text-right tabular-nums">{{ $card['balance'] }} / {{ $card['stamps_required'] }}</td>
                    <td class="text-right tabular-nums">{{ $card['rewards_available'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-gray-500">Belum ada kartu stempel aktif</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
