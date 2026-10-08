# Menerapkan Tema ke Filament

Token di [design-tokens.md](design-tokens.md) diterapkan di **tiga tempat saja**. Tempat lain
(Resource, Page, Blade) hanya memakai kelas/komponen Filament.

| Tempat | Isi |
|---|---|
| `app/Providers/Filament/{Panel}PanelProvider.php` | Palet warna, font, brand, perilaku panel |
| `resources/css/filament/{panel}/theme.css` | Override visual (sidebar, kontrol, pill, tabel) |
| `resources/views/filament/shared/brand-logo.blade.php` | Logo (mark + wordmark) |

> Nama kelas CSS hook (`.fi-*`) dan sintaks tema mengikuti versi Filament yang terpasang.
> Saat upgrade Filament, cek ulang file ini dan tampilan kedua panel.

## 1. Panel provider

```php
return Layout::apply($panel)   // App\Filament\Shared\Layout: sidebar 252/64px, ikon ☰, ⌘K, footer
    ->id('dashboard')
    ->path('dashboard')
    ->colors(Theme::colors())   // App\Filament\Shared\Theme — satu palet untuk kedua panel
    ->font('Inter')
    ->brandName('gs.POS')
    ->brandLogo(fn () => view('filament.shared.brand-logo'))
    ->brandLogoHeight('2rem')
    ->favicon(asset('images/brand/favicon.svg'))
    ->darkMode(true)
    ->maxContentWidth(Width::Full)
    ->viteTheme('resources/css/filament/dashboard/theme.css');
```

Panel `/admin` sama, kecuali `->id('admin')`, `->path('admin')`, `->authGuard('admin')`, dan tema
`resources/css/filament/admin/theme.css`.

> **Palet primary wajib eksplisit (shade 50–950).** Jika hanya hex, Filament menganggapnya shade 500
> sehingga navy berubah menjadi biru muda dan tombol memakai teks gelap. Di `Theme::colors()`
> `#2D4282` = shade 600 (tombol/link) dan navy brand `#1B2A55` = shade 800.

## 2. Struktur `theme.css`

Kedua panel memakai satu file token bersama agar tidak ada duplikasi:

```
resources/css/filament/
├── shared/
│   ├── tokens.css        # variabel :root & .dark dari design-tokens.md
│   └── components.css    # override komponen (kontrol, pill, tabel, modal)
├── dashboard/theme.css   # import shared + sidebar navy
└── admin/theme.css       # import shared + sidebar gelap + penanda Super Admin
```

```css
/* resources/css/filament/dashboard/theme.css */
@import '../../../../vendor/filament/filament/resources/css/theme.css';
@import '../shared/tokens.css';
@import '../shared/components.css';

@source '../../../../app/Filament/Dashboard/**/*';
@source '../../../../app/Filament/Shared/**/*';
@source '../../../../resources/views/filament/**/*';
```

```css
/* resources/css/filament/shared/tokens.css (potongan) */
:root {
    --brand-navy: #1B2A55;
    --brand-gray: #8A919E;
    --brand-mist: #B8C0D4;

    --sidebar-bg: var(--brand-navy);
    --sidebar-text: var(--brand-mist);
    --sidebar-hover: rgb(255 255 255 / .06);
    --sidebar-active-bg: rgb(184 192 212 / .16);

    --control-h: 36px;
    --control-h-sm: 30px;
    --control-radius: 8px;
    --control-font: 13px;
}
```

```css
/* resources/css/filament/shared/components.css (potongan) */

/* Sidebar navy seperti gs-task-tracker */
.fi-sidebar { background: var(--sidebar-bg); }
.fi-sidebar-item-label, .fi-sidebar-item-icon { color: var(--sidebar-text); }
.fi-sidebar-item-active { background: var(--sidebar-active-bg); box-shadow: inset 3px 0 0 var(--brand-mist); }

/* Nominal selalu tabular agar kolom angka sejajar */
.fi-ta-text-item.is-money, .fi-in-text-item.is-money { font-variant-numeric: tabular-nums; }
```

## 3. Build aset

- **Tema (Vite/Tailwind v4):** Node.js tidak tersedia di server (lihat SPEC). Jalankan `npm run build`
  di lokal (Node 20.19+ / 22.12+), lalu commit `public/build`.
- **Aset bawaan Filament** (`public/js|css|fonts/filament`): tidak di-commit; di-publish otomatis oleh
  `composer install` lewat hook `post-autoload-dump` → `php artisan filament:upgrade`.
- **Terjemahan** yang belum ada di Filament ditimpa di `lang/vendor/filament-panels/id/...`.

### Override view Filament

| View | Alasan |
|---|---|
| `resources/views/vendor/filament-panels/components/user-menu.blade.php` | Menu akun di topbar menampilkan **nama + avatar + caret** seperti gs-task-tracker. Salinan view Filament 5.10; bandingkan dengan `vendor/filament/filament/resources/views/components/user-menu.blade.php` setiap upgrade Filament. |

## 4. Logo

Wordmark mengikuti pola `gs.TaskTracker`: **`gs`** abu (`--brand-gray`, 800) + **`.POS`** navy (800),
font Nunito. Di sidebar gelap: `gs` mist, `.POS` putih.
Mark (ikon monitor) **tanpa kotak latar** seperti gs-task-tracker: garis navy + aksen abu di latar terang,
garis putih + aksen mist di sidebar & dark mode (kelas `.bm-frame` / `.bm-accent`, `components.css`). File SVG disimpan di
`public/images/brand/` (`logo.svg`, `logo-white.svg`, `favicon.svg`).

## 5. Checklist perubahan tema

- [ ] Nilai baru sudah ditambahkan ke `design-tokens.md` lebih dulu.
- [ ] Dicek di light & dark mode, kedua panel.
- [ ] Dicek di lebar 375px dan 1440px.
- [ ] Kontras teks ≥ 4.5:1 (cek dengan DevTools).
- [ ] `npm run build` dijalankan dan `public/build` ikut di-commit.
