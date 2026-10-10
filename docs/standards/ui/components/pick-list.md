# Daftar pilih (pick list)

Untuk memilih beberapa item dari daftar pendek–sedang (mis. outlet). Meniru daftar PIC gs-task-tracker.

```
Outlet (1 dipilih)*
┌──────────────────────────────────────────┐
│ 🔍 Cari outlet…                          │
├──────────────────────────────────────────┤
│ ☑  (K) Kopi Senja Kemang         DEMO01  │
│ ☐  (K) Kopi Senja Cilandak        KSC01  │
└──────────────────────────────────────────┘
Karyawan hanya bisa login di perangkat outlet yang dipilih
```

- Komponen: `App\Filament\Shared\Forms\OutletPickList` (turunan `CheckboxList`). Sumber pilihan lewat
  `->outletQuery(fn () => …)` yang **sudah dibatasi** tenant & akses user; nama outlet selalu di-escape.
- Label dengan jumlah terpilih: `->countedLabel('Outlet')`. Di dalam Section yang sudah berjudul: `->hiddenLabel()`.
- Kode outlet rata kanan; disembunyikan otomatis bila kotak ≤ 320px (container query).
- **Sembunyikan** field bila hanya ada satu pilihan (mis. bisnis satu outlet) — nilai tidak dikirim = tidak diubah.
- CSS: `.gs-pick-list*` di `resources/css/filament/shared/components.css`.
