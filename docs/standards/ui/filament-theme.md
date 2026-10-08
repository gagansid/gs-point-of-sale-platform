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
return $panel
    ->id('dashboard')
    ->path('dashboard')
    ->colors([
        'primary' => '#2D4282',
        'gray' => Color::Slate,
        'success' => '#2FB344',
        'warning' => '#F59F00',
        'danger' => '#D63939',
        'info' => '#066FD1',
    ])
    ->font('Inter')
    ->brandName('gs.POS')
    ->brandLogo(fn () => view('filament.shared.brand-logo'))
    ->brandLogoHeight('2rem')
    ->favicon(asset('images/brand/favicon.svg'))
    ->darkMode(true)
    ->sidebarCollapsibleOnDesktop()
    ->maxContentWidth(MaxWidth::Full)
    ->viteTheme('resources/css/filament/dashboard/theme.css');
```

Panel `/admin` sama, kecuali `->id('admin')`, `->path('admin')`, `->authGuard('admin')`, dan tema
`resources/css/filament/admin/theme.css`.

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

Node.js tidak tersedia di server (lihat SPEC). Jalankan `npm run build` di lokal, lalu commit hasil
`public/build`. Pastikan `public/build` **tidak** ada di `.gitignore`.

## 4. Logo

Wordmark mengikuti pola `gs.TaskTracker`: **`gs`** abu (`--brand-gray`, 800) + **`.POS`** navy (800),
font Nunito. Di sidebar gelap: `gs` mist, `.POS` putih. File SVG disimpan di
`public/images/brand/` (`logo.svg`, `logo-white.svg`, `favicon.svg`).

## 5. Checklist perubahan tema

- [ ] Nilai baru sudah ditambahkan ke `design-tokens.md` lebih dulu.
- [ ] Dicek di light & dark mode, kedua panel.
- [ ] Dicek di lebar 375px dan 1440px.
- [ ] Kontras teks ≥ 4.5:1 (cek dengan DevTools).
- [ ] `npm run build` dijalankan dan `public/build` ikut di-commit.
