# Button

**Tujuan:** memicu satu aksi yang jelas.

## Kapan dipakai

- Aksi halaman (Tambah, Simpan, Export), aksi modal, aksi kontekstual di header detail.
- **Bukan** untuk navigasi ke halaman lain di dalam teks (pakai link), dan **bukan** untuk aksi per
  baris tabel (pakai [row-actions.md](row-actions.md)).

## Anatomi

`[ikon 16px] Label` — tinggi 32px, padding horizontal 14px, jarak ikon–label 6px, radius 8px.

## Varian

| Varian | Filament | Kapan |
|---|---|---|
| Primary | `->color('primary')` (default) | Satu aksi utama per halaman/modal |
| Secondary | `->color('gray')` atau `->outlined()` | Aksi pendamping (Export, Filter, Batal) |
| Danger | `->color('danger')` | Aksi merusak: Void, Hapus, Cabut akses — selalu dengan konfirmasi |
| Ghost danger | `->link()->color('danger')` | Aksi merusak sekunder di footer form (Hapus produk) |
| Link | `->link()` | Aksi ringan di dalam kartu ("Lihat semua") |
| Icon only | `->iconButton()` | Toolbar padat; wajib `->tooltip()` |

| Ukuran | Tinggi | Filament | Kapan |
|---|---|---|---|
| sm | 28px | `->size('sm')` | Toolbar tabel, header kartu |
| md | 32px | default | Umum |
| lg | 40px | `->size('lg')` | Aksi utama form panjang |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Font | 13px / 500 |
| Radius | `--control-radius` 8px |
| Ikon | 15px, Heroicons outline |
| Jarak antar tombol | 8px |
| Lebar minimum di modal konfirmasi | 96px |

## Perilaku & state

| State | Tampilan |
|---|---|
| Hover | Primary → `primary-dk`; secondary → border lebih gelap |
| Focus (keyboard) | `--focus-ring` |
| Disabled | Opacity 60%, `cursor: not-allowed`; jelaskan alasannya lewat tooltip |
| Loading | Spinner menggantikan ikon, label tetap, tombol disabled — mencegah submit ganda |

## Aksesibilitas

- Label berupa kata kerja yang menjelaskan hasil: "Simpan produk", bukan "OK".
- Tombol ikon wajib `->tooltip()` (dipakai juga sebagai `aria-label`).
- Urutan di grup: aksi pembatal di kiri, aksi utama paling kanan.

## Implementasi

```php
// Header halaman daftar
protected function getHeaderActions(): array
{
    return [
        ExportAction::make()->label('Export Excel')->color('gray')->icon('heroicon-o-arrow-down-tray'),
        CreateAction::make()->label('Tambah produk')->icon('heroicon-o-plus'),
    ];
}

// Aksi berbahaya di halaman detail order
Action::make('void')
    ->label('Void')
    ->color('danger')
    ->icon('heroicon-o-x-circle')
    ->visible(fn (Order $record) => $record->canBeVoided())
    ->requiresConfirmation()
    ->action(fn (Order $record, array $data) => app(VoidOrder::class)->handle(auth()->user(), $record, $data['reason']));
```

## Do / Don't

| Do | Don't |
|---|---|
| Satu primary per layar | Dua tombol primary berdampingan |
| "Tambah produk" | "Create", "Baru", "+" saja tanpa label |
| Aksi merusak berwarna danger + konfirmasi | Void/Hapus berwarna primary |
| Memanggil Action di `->action()` | Menulis logika bisnis di closure `->action()` |
