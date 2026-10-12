{{-- Hasil stok opname (ADR 0015): stok sistem, hitungan fisik, stok saat posting, selisih qty & nilai. --}}
@php
    use App\Support\Money;
    /** @var \App\Models\StockOpname $record */
    $record = $getRecord();
    $tz = \App\Support\CurrentOutlet::timezone();
    $num = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');
    $signed = fn (?int $n): string => $n === null ? '—' : ($n > 0 ? '+' : '').number_format($n, 0, ',', '.');
@endphp
<div class="gs-nota">
    <dl class="gs-nota-meta">
        <div><dt>Nomor</dt><dd>{{ $record->number }}</dd></div>
        <div><dt>Status</dt><dd>{{ $record->status->getLabel() }}</dd></div>
        <div><dt>Outlet</dt><dd>{{ $record->outlet?->name }}</dd></div>
        <div><dt>Lingkup</dt><dd>{{ $record->category ? 'Kategori '.$record->category->name : 'Semua produk lacak stok' }}</dd></div>
        <div><dt>Tanggal hitung</dt><dd>{{ $record->counted_on->locale('id')->translatedFormat('j M Y') }}</dd></div>
        @if ($record->posted_at)
            <div><dt>Diposting</dt><dd>{{ $record->posted_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }} · {{ $record->postedBy?->name }}</dd></div>
        @endif
        @if (filled($record->notes))
            <div><dt>Catatan</dt><dd>{{ $record->notes }}</dd></div>
        @endif
    </dl>

    <div class="pos-dash-table-wrap">
        <table class="pos-dash-table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th class="text-right">Stok saat posting</th>
                    <th class="text-right">Fisik</th>
                    <th class="text-right">Selisih</th>
                    <th class="text-right">Nilai selisih</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $item)
                    <tr wire:key="oi-{{ $item->id }}">
                        <td>{{ $item->product_name }}</td>
                        <td class="text-right tabular-nums">{{ $num($item->stock_at_post ?? $item->system_qty) }}</td>
                        <td class="text-right tabular-nums">{{ $item->counted_qty === null ? 'tidak dihitung' : $num($item->counted_qty) }}</td>
                        <td @class(['text-right tabular-nums', 'text-danger-600 dark:text-danger-400' => ($item->difference ?? 0) < 0])>{{ $signed($item->difference) }}</td>
                        <td class="text-right tabular-nums">{{ $item->difference_value !== null ? Money::format($item->difference_value) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            @unless ($record->isDraft())
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th></th>
                        <th></th>
                        <th class="text-right tabular-nums">{{ $signed($record->difference_qty) }}</th>
                        <th class="text-right tabular-nums">{{ Money::format($record->difference_value) }}</th>
                    </tr>
                </tfoot>
            @endunless
        </table>
    </div>
</div>
