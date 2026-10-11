{{-- Ringkasan harian (Q57). Nominal dari DailySummaryBuilder (string desimal). --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
@php($outlets = $summary['outlets'])
@php($totals = $summary['totals'])
<x-mail::message>
# Ringkasan {{ $date }}

Halo {{ $name }}, berikut rekap penjualan **{{ $business }}** kemarin.

<x-mail::panel>
**Omzet {{ $money($totals['revenue']) }}** dari {{ number_format($totals['order_count'], 0, ',', '.') }} transaksi
@if ($totals['void_count'] > 0)
· {{ $totals['void_count'] }} void ({{ $money($totals['void_total']) }})
@endif
</x-mail::panel>

<x-mail::table>
| Outlet | Omzet | Transaksi | Void | Selisih kas | Target |
|:--|--:|--:|--:|--:|--:|
@foreach ($outlets as $row)
| {{ $row['name'] }} | {{ $money($row['revenue']) }} | {{ $row['order_count'] }} | {{ $row['void_count'] > 0 ? $row['void_count'].' ('.$money($row['void_total']).')' : '–' }} | {{ $row['shift_count'] > 0 ? $money($row['cash_difference']) : '–' }} | {{ $row['target_percent'] !== null ? str_replace('.', ',', $row['target_percent']).'%' : '–' }} |
@endforeach
</x-mail::table>

@php($attention = collect($outlets)->flatMap(fn (array $r): array => array_filter([
    $r['void_count'] > 0 ? "{$r['name']}: {$r['void_count']} transaksi di-void" : null,
    $r['shift_count'] > 0 && \App\Support\Money::compare($r['cash_difference'], '0') !== 0 ? "{$r['name']}: selisih kas {$money($r['cash_difference'])}" : null,
    $r['target_percent'] !== null && (float) $r['target_percent'] < 100 ? "{$r['name']}: target harian tercapai ".str_replace('.', ',', $r['target_percent']).'%' : null,
])))
@if ($attention->isNotEmpty())
**Perlu dicek**

@foreach ($attention as $line)
- {{ $line }}
@endforeach
@endif

<x-mail::button :url="$reportsUrl">
Buka laporan
</x-mail::button>

Email ini dikirim setiap pagi. Matikan di dashboard, menu Profil → Ringkasan harian.

Salam,<br>
tim gs.POS
</x-mail::message>
