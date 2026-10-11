@php
    // Retry-After dari rate limiter (detik); hanya angka yang ditampilkan
    $retryAfter = (int) (isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? 0) : 0);
@endphp
<x-site.error-page
    code="429"
    title="Terlalu banyak percobaan"
    :message="$retryAfter > 0
        ? 'Demi keamanan, permintaan dibatasi sementara. Coba lagi dalam '.max(1, (int) ceil($retryAfter / 60)).' menit.'
        : 'Demi keamanan, permintaan dibatasi sementara. Tunggu sebentar lalu coba lagi.'"
/>
