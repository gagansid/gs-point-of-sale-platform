{{-- Kerangka halaman masuk kasir web (login, masuk ulang, pilih outlet): x-auth.layout dengan isi panel kasir. --}}
@props(['title' => 'Kasir web'])
<x-auth.layout :title="$title" app="Kasir web"
    heading="Siap melayani dari browser mana pun"
    text="Satu layar untuk mencatat pesanan, menerima pembayaran, dan menutup shift."
    :points="[['clock', 'Shift & kas tercatat rapi'], ['card', 'Tunai, QRIS, kartu, atau bayar terpisah'], ['receipt', 'Cetak struk langsung dari browser']]">
    {{ $slot }}
</x-auth.layout>
