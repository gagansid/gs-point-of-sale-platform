# Row Actions (aksi per baris)

**Tujuan:** aksi cepat pada satu baris tabel tanpa membuka halaman detail.

## Kapan dipakai

- Lihat, ubah, nonaktifkan, hapus satu data.
- Maksimal **3 ikon** terlihat; sisanya masuk `ActionGroup` (ikon ⋯).

## Anatomi

`[👁] [✎] [⋯]` — tombol ikon 30×30px, ikon 16px, jarak 4px, rata tengah di kolom terakhir.

## Varian

Warna netral (`gray`/muted) saat diam; **berwarna hanya saat hover**, sesuai pola `gs-task-tracker`:

| Aksi | Ikon | Warna hover | Konfirmasi |
|---|---|---|---|
| Lihat | `eye` | primary | — |
| Ubah | `pencil-square` | info | — |
| Nonaktifkan | `pause-circle` | warning | Ya |
| Aktifkan | `play-circle` | success | — |
| Cetak ulang | `printer` | primary | — |
| Hapus / Void / Cabut | `trash` / `x-circle` / `no-symbol` | danger | Ya, wajib |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Ukuran | 30×30px, radius 6px |
| Ikon | Heroicons **outline** 16px (bukan solid): `eye`, `pencil`, `trash`, `ellipsis-vertical` — dipasang global lewat alias ikon di `App\Filament\Shared\Layout` |
| Warna diam | `text-muted` |
| Hover | Warna aksi + latar warna aksi alpha 12% (gray → primary, primary → biru `#066FD1`, danger → merah) |
| Focus | Outline 2px primary, offset 1px |

## Perilaku & state

- Aksi yang tidak diizinkan **disembunyikan** (`->visible(fn () => auth()->user()->can(...))`),
  bukan disabled.
- Aksi yang diizinkan tetapi tidak berlaku untuk baris ini (mis. Void pada order shift tertutup)
  → disabled dengan tooltip alasan.
- Klik baris = Lihat; tombol Lihat tetap ada untuk pengguna keyboard.

## Aksesibilitas

Semua tombol ikon wajib `->tooltip()`; Filament memakainya sebagai label aksesibel.

## Implementasi

```php
->actions([
    ViewAction::make()->iconButton()->tooltip('Lihat'),
    EditAction::make()->iconButton()->tooltip('Ubah'),
    ActionGroup::make([
        Action::make('toggleActive')
            ->label(fn (User $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
            ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->requiresConfirmation(fn (User $record) => $record->is_active)
            ->action(fn (User $record) => app(ToggleUserActive::class)->handle(auth()->user(), $record)),
        DeleteAction::make()->label('Hapus'),
    ])->tooltip('Aksi lain'),
])
```

Warna hover per jenis diatur di `components.css` berdasarkan kelas yang ditambahkan lewat
`->extraAttributes(['class' => 'act-edit'])`, bukan dengan `->color()` (agar tetap netral saat diam).

## Do / Don't

| Do | Don't |
|---|---|
| Ikon netral, berwarna saat hover | Deretan ikon berwarna-warni |
| ≤ 3 ikon + grup ⋯ | 5–6 ikon dalam satu baris |
| Sembunyikan aksi tanpa permission | Menampilkan aksi lalu menolak dengan error |
