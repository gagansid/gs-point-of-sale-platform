{{-- Fallback semua error 5xx lain. --}}
<x-site.error-page
    :code="isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500"
    title="Layanan sedang terganggu"
    message="Maaf, sistem sedang tidak dapat melayani permintaan. Silakan coba lagi beberapa saat lagi."
    :show-request-id="true"
/>
