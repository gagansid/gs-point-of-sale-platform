# Standar UI

Acuan tampilan panel **`/dashboard`** (owner/manager) dan **`/admin`** (super admin), keduanya Filament.

## Referensi visual

Bahasa visual diambil dari project internal **`gs-task-tracker`**
(`/Users/gagans/dev/project/gs-task-tracker`, file `public/assets/css/brand.css` & `app.css`):

- sidebar navy pekat, konten abu sangat muda, kartu putih dengan border tipis;
- tombol & input setinggi 36px, radius 8px, font 13px;
- badge status berbentuk **pill** berbingkai warna dengan latar transparan;
- tombol aksi baris berupa ikon 30px yang berwarna hanya saat hover;
- modal konfirmasi dengan ikon di samping judul dan tombol rata kanan.

Yang diambil adalah **token dan pola**, bukan kodenya. `gs-task-tracker` memakai PHP + Gentelella,
sedangkan project ini memakai komponen Filament yang diberi tema.

## Prinsip

1. **Pakai komponen Filament dulu.** Blade kustom hanya jika tidak ada komponen yang sesuai,
   dan harus memakai token yang sama.
2. **Satu sumber gaya.** Warna, ukuran, dan radius hanya dari [design-tokens.md](design-tokens.md),
   diterapkan di `theme.css`. Dilarang memakai warna hex atau `style=""` di Resource.
3. **Tenang dan padat.** Warna hanya untuk makna (status, aksi berbahaya). Sebagian besar layar
   berwarna netral; primary dipakai untuk satu aksi utama per layar.
4. **Angka mudah dibaca.** Nominal rata kanan, `tabular-nums`, format Rupiah konsisten.
5. **Bahasa Indonesia yang singkat** untuk semua label, pesan, dan tombol ([content.md](content.md)).
6. **Light & dark mode** keduanya didukung dan dicek.
7. **Layar besar dulu**, tetapi semua halaman tetap bisa dipakai di lebar 375px.

## Dokumen

| File | Isi |
|---|---|
| [design-tokens.md](design-tokens.md) | Warna, tipografi, spacing, radius, bayangan, dark mode |
| [filament-theme.md](filament-theme.md) | Cara menerapkan token ke panel Filament |
| [layout.md](layout.md) | Kerangka panel, navigasi, anatomi halaman |
| [content.md](content.md) | Bahasa, format uang/tanggal/angka, penamaan label |
| `components/` | Satu file per komponen (lihat tabel di bawah) |

### Komponen

| Komponen | File | Filament |
|---|---|---|
| Button | [components/button.md](components/button.md) | `Action`, `<x-filament::button>` |
| Form & input | [components/form.md](components/form.md) | `TextInput`, `Select`, `Toggle`, ... |
| Money input | [components/money-input.md](components/money-input.md) | `App\Filament\Shared\Forms\MoneyInput` |
| Daftar pilih (outlet) | [components/pick-list.md](components/pick-list.md) | `App\Filament\Shared\Forms\OutletPickList` |
| Audit dibuat/diubah | [components/audit-info.md](components/audit-info.md) | `AuditInfo`, `AuditColumns` |
| Table | [components/table.md](components/table.md) | `Table`, `TextColumn`, `Filter` |
| Row actions | [components/row-actions.md](components/row-actions.md) | `Action::make()->iconButton()` |
| Badge / pill | [components/badge.md](components/badge.md) | `TextColumn::badge()`, enum `HasColor` |
| Section / card | [components/section.md](components/section.md) | `Section`, `Fieldset` |
| Detail (infolist) | [components/infolist.md](components/infolist.md) | `Infolist`, `TextEntry` |
| Stat widget | [components/stat-widget.md](components/stat-widget.md) | `StatsOverviewWidget` |
| Chart | [components/chart.md](components/chart.md) | `ChartWidget` |
| Modal | [components/modal.md](components/modal.md) | `Action::form()`, `requiresConfirmation()` |
| Notification | [components/notification.md](components/notification.md) | `Notification::make()` |
| Empty state | [components/empty-state.md](components/empty-state.md) | `emptyStateHeading()` |
| Tabs | [components/tabs.md](components/tabs.md) | `Tabs`, `getTabs()` |
| Icon | [components/icon.md](components/icon.md) | Heroicons |

## Format dokumen komponen

Setiap file di `components/` memakai urutan bagian yang sama agar mudah dibandingkan:

```
# {Nama komponen}
Tujuan            — satu kalimat
Kapan dipakai     — dan kapan TIDAK
Anatomi           — bagian-bagiannya
Varian            — tabel varian + kapan
Spesifikasi       — token yang dipakai (ukuran, warna, jarak)
Perilaku & state  — hover, focus, disabled, loading, error
Aksesibilitas     — label, fokus keyboard, kontras
Implementasi      — contoh kode Filament
Do / Don't
```

Komponen baru → salin struktur ini dan tambahkan ke tabel di atas.
