# ADR 0004: PHP 8.2 dengan Laravel 12

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda

## Konteks

SPEC menyebut "Laravel versi terbaru (PHP 8.3+ disarankan)". Laravel 13 mensyaratkan PHP ^8.3,
sedangkan lingkungan pengembangan memakai PHP 8.2.

## Keputusan

Tetap di **PHP 8.2**. Versi package dikunci ke rilis terakhir yang mendukung PHP 8.2:

| Package | Versi |
|---|---|
| `laravel/framework` | ^12.0 |
| `filament/filament` | ^5.0 (Livewire 4) |
| `laravel/sanctum` | ^4.0 |
| `pestphp/pest` | ^3.8 (Pest 4 butuh PHP 8.3) |
| `larastan/larastan` | ^3.0 |

## Konsekuensi

- Fitur PHP 8.3+ tidak boleh dipakai (typed class constants, `#[\Override]`, `json_validate()`).
- CI memakai PHP 8.2 agar kode yang lolos CI pasti jalan di lokal & hosting.
- Laravel 12 menerima security fix sampai Februari 2027. Rencanakan upgrade ke PHP 8.3+ dan
  Laravel 13 sebelum itu, sebagai ADR baru yang menggantikan ADR ini.
