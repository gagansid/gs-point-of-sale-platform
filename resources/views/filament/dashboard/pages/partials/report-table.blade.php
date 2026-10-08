{{-- Tabel laporan sederhana: kolom pertama teks, sisanya angka rata kanan (table.md) --}}
@if ($rows === [])
    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada data pada periode ini</p>
@else
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                @foreach ($columns as $i => $column)
                    <th class="pb-2 {{ $i > 0 ? 'text-right' : '' }}">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td class="py-2 {{ $i > 0 ? 'text-right tabular-nums' : 'font-medium text-gray-950 dark:text-white' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
