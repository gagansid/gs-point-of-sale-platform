# Notification (toast) & Alert

**Tujuan:** memberi umpan balik singkat setelah aksi, atau peringatan yang menetap di halaman.

## Kapan dipakai

| Komponen | Kapan |
|---|---|
| Toast (`Notification::make()`) | Hasil aksi: berhasil disimpan, gagal karena aturan bisnis |
| Alert inline (callout di atas konten) | Kondisi yang menetap: tenant trial hampir habis, pengumuman admin, maintenance terjadwal |
| Notifikasi database (bell) | Tidak dipakai di MVP |

## Anatomi

Toast: `[ikon] Judul (1 kalimat)` + body opsional, pojok kanan atas, maks. lebar 360px.
Alert: kartu penuh lebar, border kiri 3px warna status, latar warna status alpha 6%.

## Varian

| Status | Ikon | Durasi toast |
|---|---|---|
| success | `check-circle` | 4 detik |
| info | `information-circle` | 5 detik |
| warning | `exclamation-triangle` | 6 detik |
| danger | `x-circle` | Menetap sampai ditutup |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Judul | 14px / 600 |
| Body | 13px, `text-secondary` |
| Radius | 12px |
| Bayangan | `--shadow-pop` |

## Perilaku & state

- Satu aksi = satu toast. Jangan menumpuk toast sukses untuk setiap baris pada bulk action;
  tampilkan satu ringkasan ("12 produk berhasil dinonaktifkan").
- Pesan error memakai `BusinessException::getMessage()` (sudah Bahasa Indonesia dari Action).
- `SERVER_ERROR` → "Terjadi kesalahan pada server. Coba lagi atau hubungi admin" (detail di log).

## Aksesibilitas

Toast memakai `role="status"` (success/info) atau `role="alert"` (danger) — bawaan Filament.
Toast danger tidak hilang otomatis agar sempat dibaca.

## Implementasi

```php
Notification::make()
    ->success()
    ->title('Produk berhasil disimpan')
    ->send();

Notification::make()
    ->danger()
    ->title('Shift sudah ditutup')
    ->body('Void hanya bisa dilakukan pada shift yang masih terbuka')
    ->persistent()
    ->send();
```

Banner pengumuman dari `/admin` (tabel `announcements`) dirender sebagai alert inline di atas
konten panel `/dashboard` lewat render hook `PanelsRenderHook::CONTENT_START`.

## Do / Don't

| Do | Don't |
|---|---|
| "Produk berhasil disimpan" | "Sukses!", "Data saved successfully" |
| Error danger tetap tampil | Error hilang dalam 3 detik |
| Alert inline untuk kondisi menetap | Toast berulang setiap kali halaman dibuka |
