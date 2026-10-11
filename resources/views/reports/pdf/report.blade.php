{{-- PDF laporan (ADR 0012): kop, info filter, stat ringkas, satu tabel per bagian. Semua teks di-escape. --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
@php($cell = function (\App\Exports\ReportSection $section, int $i, mixed $value) use ($money): string {
    if ($value === null) {
        return '';
    }
    if ($section->isMoney($i) && is_string($value) && is_numeric($value)) {
        return $money($value);
    }

    return is_int($value) ? number_format($value, 0, ',', '.') : (string) $value;
})
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('reports.pdf.partials.styles')
</head>
<body>
    <header class="kop">
        <div class="meta">{{ $tenant }}<br>{{ $outlet }}</div>
        <div class="brand"><span>gs</span>.POS</div>
    </header>
    <footer>
        Dicetak oleh {{ $printedBy }} · {{ $printedAt }}
    </footer>

    <h1>{{ $title }}</h1>
    <table class="info">
        @foreach ($info as [$label, $value])
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @if ($stats !== [])
        <table class="stats">
            <tr>
                @foreach ($stats as [$label, $value])
                    <td><div class="label">{{ $label }}</div><div class="value">{{ $value }}</div></td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach ($sections as $section)
        <h2>{{ $section->title }}</h2>
        @if ($section->rows === [])
            <div class="empty">Belum ada data pada periode ini</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($section->headings as $i => $heading)
                            <th class="{{ $i > 0 && ($section->isMoney($i) || is_numeric($section->rows[0][$i] ?? null)) ? 'num' : '' }}">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->rows as $row)
                        <tr>
                            @foreach ($row as $i => $value)
                                <td class="{{ $i > 0 && (is_int($value) || is_numeric($value)) ? 'num' : '' }} {{ $section->isMoney($i) && is_string($value) && str_starts_with($value, '-') ? 'minus' : '' }}">{{ $cell($section, $i, $value) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($section->totals !== null)
                    <tfoot>
                        <tr>
                            @foreach ($section->totals as $i => $value)
                                <td class="{{ $i > 0 ? 'num' : '' }}">{{ $cell($section, $i, $value) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        @endif
    @endforeach
</body>
</html>
