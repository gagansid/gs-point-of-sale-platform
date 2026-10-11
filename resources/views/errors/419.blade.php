@php
    // Token CSRF kedaluwarsa (halaman terlalu lama dibuka / sesi habis). Kembali ke halaman asal hanya bila
    // host-nya sama: Referer dari situs lain tidak boleh dijadikan tujuan tombol (open redirect).
    $previous = url()->previous();
    $back = parse_url($previous, PHP_URL_HOST) === request()->getHost() ? $previous : url('/');
@endphp
<x-site.error-page
    code="419"
    title="Sesi kedaluwarsa"
    message="Halaman ini terlalu lama dibuka sehingga sesinya habis. Muat ulang halaman lalu coba lagi."
    action-label="Muat ulang halaman"
    :action-url="$back"
/>
