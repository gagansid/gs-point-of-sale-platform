# ADR 0012: Export laporan PDF dengan dompdf

- Status: Diterima
- Tanggal: 2026-10-11
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q52, ADR 0004 (PHP 8.2 & Laravel 12), target hosting cPanel

## Konteks

Owner, manager, dan supervisor perlu mengunduh laporan dalam bentuk PDF (rekap untuk dicetak,
dikirim ke pemilik, atau arsip Z-report shift). Aplikasi berjalan di shared hosting cPanel: tidak ada
Chrome/Node, tidak boleh proses yang berjalan terus, dan tidak ada queue worker (export sinkron).

## Pilihan

| Opsi | Cara kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| A. `barryvdh/laravel-dompdf` (dompdf) | HTML + CSS → PDF dengan PHP murni | Jalan di shared hosting, tanpa biner, integrasi Laravel matang | CSS terbatas (tanpa flex/grid modern, tanpa JS/grafik canvas) |
| B. Browsershot / Puppeteer | Chrome headless merender halaman | CSS & grafik persis seperti layar | Butuh Chrome + Node: tidak tersedia di cPanel |
| C. wkhtmltopdf (Snappy) | Biner Qt WebKit | Render lebih lengkap dari dompdf | Butuh biner, proyek sudah tidak dirawat |
| D. mPDF | HTML → PDF PHP murni | Dukungan font & RTL bagus | Lebih berat, API kurang terintegrasi dengan Laravel |

## Keputusan

Kami memilih **A: `barryvdh/laravel-dompdf` ^3.1** (dompdf ^3.0; mendukung PHP ^8.1 dan Laravel 9–13).

| Topik | Keputusan |
|---|---|
| Template | Blade khusus di `resources/views/reports/pdf/*`, A4 portrait (landscape untuk tabel lebar) |
| Gaya | CSS khusus PDF di satu partial; hex wajib sama dengan `design-tokens.md` (dompdf tidak membaca `theme.css`). Font Plus Jakarta Sans di-embed |
| Grafik | Tabel ringkas; bar sederhana dengan elemen HTML/CSS (dompdf tidak menjalankan JS) |
| Keamanan | `isRemoteEnabled = false` (tanpa resource dari luar), `isPhpEnabled = false`, semua teks user lewat `{{ }}` |
| Eksekusi | Sinkron di request (Q52); data sudah teragregasi di SQL, maks. 366 hari |

## Konsekuensi

- Positif: PDF bisa dibuat di shared hosting tanpa dependensi sistem; satu sumber angka (`ReportService`).
- Negatif: tampilan PDF tidak identik dengan dashboard (tanpa grafik Chart.js); laporan sangat besar bisa
  lambat, karena itu batas periode & penolakan "Persempit rentang tanggal" (Q52).
- Tindak lanjut: tambah paket ke `composer.json`, publish konfigurasi dengan opsi keamanan di atas, test
  respons `application/pdf`, permission, dan isolasi tenant.
