# Modal

**Tujuan:** meminta konfirmasi atau input singkat tanpa meninggalkan halaman.

## Kapan dipakai

| Jenis | Kapan |
|---|---|
| Konfirmasi | Aksi merusak/tidak bisa dibatalkan: void, hapus, cabut device, tutup paksa shift, suspend tenant |
| Form singkat | ≤ 5 field: penyesuaian stok, alasan void, atur PIN, ubah status tenant |
| Slide-over | Detail cepat tanpa pindah halaman (opsional) |

**Tidak** dipakai untuk form > 5 field (pakai halaman), atau untuk informasi yang bisa ditampilkan inline.

## Anatomi — konfirmasi

Mengikuti pola `gs-task-tracker` (`.confirm-dialog`):

```
┌──────────────────────────────────────────────┐
│ [⚠]  Void transaksi                      [×] │  ikon 36px kotak radius 10px, judul 15px/600
├──────────────────────────────────────────────┤
│ Yakin ingin membatalkan transaksi            │  pertanyaan 14px, nama data <strong>
│ **DEMO01-261008-0042**?                      │
│ Stok dikembalikan dan transaksi tidak bisa   │  catatan 13px muted, rata kiri
│ dipulihkan.                                  │
│ Alasan *  [_____________________________]    │  (field opsional)
│                                              │
├──────────────────────────────────────────────┤
│                        [Batal]  [Void order] │  rata kanan, min 96px
└──────────────────────────────────────────────┘
```

Implementasi bersama di `App\Filament\Shared\Layout`:

- Semua modal: `modalAlignment(Start)` + `modalFooterActionsAlignment(End)` (lewat `Action::configureUsing`).
- Judul = nama aksi ("Hapus kategori"), deskripsi = `Layout::confirmText('Yakin ingin … <strong>'.e($nama).'</strong>?', 'Catatan.')`.
  **Nama data wajib di-escape dengan `e()`** sebelum dimasukkan ke pertanyaan (HTML); catatan di-escape otomatis.
- `DeleteAction` / `DeleteBulkAction` mendapat deskripsi baku; ikon modal hapus `exclamation-triangle`.
- **Keluar** juga lewat modal konfirmasi (ikon `arrow-right-start-on-rectangle`, tombol primary "Keluar").

## Varian

| Varian | Ikon & warna | Tombol konfirmasi |
|---|---|---|
| Danger | `exclamation-triangle`, latar danger-lt | `danger`, label = aksi ("Void order", "Hapus produk") |
| Netral | `question-mark-circle`, latar primary-lt | `primary` |
| Form | Tanpa ikon, judul = aksi | `primary` "Simpan" |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Lebar | Konfirmasi `sm` (≈440px); form `md`/`lg` |
| Posisi | Atas layar, jarak `min(12vh, 96px)` dari atas (mobile 16px) — override grid `.fi-modal-window-ctn` |
| Radius | 8px, border 1px `--border-color`, overlay `rgba(15,23,42,.45)` + blur 3px |
| Padding body | 18px × 24px |
| Tinggi maks | Layar − 96px; hanya body yang di-scroll, header & footer tetap |
| Tombol footer | Rata kanan, jarak 8px, min 96px |

## Perilaku & state

- Tombol konfirmasi menampilkan loading dan mencegah klik ganda.
- Esc / klik latar menutup modal **kecuali** modal form yang sudah diisi.
- Error dari Action (`BusinessException`) ditampilkan sebagai [notification](notification.md) danger,
  modal tetap terbuka agar user bisa memperbaiki.
- Setelah sukses: modal tertutup + notifikasi sukses + tabel di-refresh.

## Aksesibilitas

- Fokus pindah ke field pertama (form) atau tombol Batal (konfirmasi danger).
- Judul modal menjadi `aria-labelledby`.

## Implementasi

```php
Action::make('void')
    ->label('Void')
    ->color('danger')
    ->icon('heroicon-o-x-circle')
    ->requiresConfirmation()
    ->modalIcon('heroicon-o-exclamation-triangle')
    ->modalHeading(fn (Order $record) => "Void order {$record->order_number}?")
    ->modalDescription('Stok dikembalikan dan transaksi tidak bisa dipulihkan')
    ->modalSubmitActionLabel('Void order')
    ->modalCancelActionLabel('Batal')
    ->modalWidth(MaxWidth::Medium)
    ->form([
        Textarea::make('reason')->label('Alasan')->required()->maxLength(255)->rows(2),
    ])
    ->action(function (Order $record, array $data, Action $action) {
        try {
            app(VoidOrder::class)->handle(auth()->user(), $record, $data['reason']);
            Notification::make()->success()->title('Transaksi berhasil dibatalkan')->send();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    });
```

Pola `try/catch BusinessException → Notification + halt()` dibungkus di trait Shared
`HandlesBusinessException` agar tidak ditulis ulang.

## Do / Don't

| Do | Don't |
|---|---|
| Judul menyebut objek: "Hapus produk Es Kopi Susu?" | "Apakah Anda yakin?" |
| Tombol menyebut aksi: "Hapus produk" | "Ya" / "OK" |
| Jelaskan konsekuensi | Modal konfirmasi tanpa deskripsi |
