{{-- Fallback semua error 4xx lain (mis. 405, 413). --}}
<x-site.error-page
    :code="isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400"
    title="Permintaan tidak dapat diproses"
    message="Permintaan ke halaman ini tidak valid. Kembali ke beranda lalu coba lagi."
/>
