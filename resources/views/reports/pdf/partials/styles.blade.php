{{--
    Gaya PDF laporan (dompdf tidak membaca theme.css). Satu-satunya tempat hex untuk PDF;
    nilai WAJIB sama dengan docs/standards/ui/design-tokens.md (ADR 0012).
    Plus Jakarta Sans dari resources/fonts (SIL OFL, lihat resources/fonts/OFL.txt); DejaVu Sans sebagai cadangan.
    Cache metrik font dompdf di storage/fonts (wajib bisa ditulis di hosting).
--}}
@php($fontRegular = resource_path('fonts/PlusJakartaSans-Regular.ttf'))
@php($fontBold = resource_path('fonts/PlusJakartaSans-Bold.ttf'))
<style>
    @if (is_file($fontRegular))
        @font-face { font-family: 'Plus Jakarta Sans'; font-weight: normal; src: url('{{ $fontRegular }}') format('truetype'); }
    @endif
    @if (is_file($fontBold))
        @font-face { font-family: 'Plus Jakarta Sans'; font-weight: bold; src: url('{{ $fontBold }}') format('truetype'); }
    @endif

    /* design-tokens.md: --brand-navy #1B2A55, primary #2D4282, --text #1E2633, --text-secondary #626D7D,
       --text-muted #7E8896, --border-color #E6E7EB, --border-color-light #EFF0F3, --bg-surface-secondary #F9FAFB,
       success #2FB344, warning #F59F00, danger #D63939 */
    @page { margin: 22mm 14mm 18mm 14mm; }
    body { font-family: 'Plus Jakarta Sans', 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1E2633; }
    header.kop { position: fixed; top: -16mm; left: 0; right: 0; height: 12mm; border-bottom: 2px solid #1B2A55; }
    header.kop .brand { font-size: 13pt; font-weight: bold; color: #1B2A55; }
    header.kop .brand span { color: #8A919E; }
    header.kop .meta { float: right; text-align: right; font-size: 8pt; color: #626D7D; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #7E8896; border-top: 1px solid #E6E7EB; padding-top: 2mm; }
    h1 { font-size: 14pt; color: #1B2A55; margin: 0 0 2mm; }
    h2 { font-size: 10.5pt; color: #2D4282; margin: 6mm 0 2mm; padding-bottom: 1mm; border-bottom: 1px solid #EFF0F3; }
    .info { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
    .info td { padding: 0.8mm 0; vertical-align: top; }
    .info td.label { width: 30mm; color: #626D7D; }
    .stats { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin: 2mm -2mm 2mm; }
    .stats td { border: 1px solid #E6E7EB; border-top: 3px solid #2D4282; padding: 2.5mm 3mm; width: 25%; }
    .stats .label { font-size: 7.5pt; color: #626D7D; }
    .stats .value { font-size: 12pt; font-weight: bold; color: #1E2633; }
    table.data { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    table.data th { background: #F9FAFB; color: #626D7D; font-size: 7.5pt; text-transform: uppercase; text-align: left; padding: 1.6mm 1.5mm; border-bottom: 1px solid #E6E7EB; }
    table.data td { padding: 1.4mm 1.5mm; border-bottom: 1px solid #EFF0F3; }
    table.data tr { page-break-inside: avoid; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data tfoot td { font-weight: bold; border-top: 2px solid #E6E7EB; background: #F9FAFB; }
    .empty { color: #7E8896; font-style: italic; padding: 2mm 0; }
    .minus { color: #D63939; }
    .ok { color: #2FB344; }
    .warn { color: #F59F00; }
    .bar { display: inline-block; height: 2mm; background: #2D4282; }
</style>
