# Badge / Pill

**Tujuan:** menunjukkan status atau kategori dalam sekali lihat.

## Kapan dipakai

- Nilai enum: status order, status shift, status tenant, kategori pembayaran, role.
- **Bukan** untuk angka penting (pakai teks biasa) atau aksi (pakai tombol).

## Anatomi

`[ Selesai ]` — kotak radius 6px, latar tint lembut warna status (shade 50), garis tipis warna status 10%, teks warna status.

## Spesifikasi

| Properti | Nilai |
|---|---|
| Tinggi | 22px |
| Padding | 2px × 8px |
| Radius | 6px |
| Font | 12px / 500 |
| Border | ring 1px warna status alpha 10% (bawaan Filament) |
| Latar | Warna status shade 50 (light) / alpha 10% (dark) |
| Ikon | Opsional, 14px, di kiri |

## Pemetaan warna baku

Warna & label **hanya** didefinisikan di enum (`HasColor`, `HasLabel`). Tabel ini adalah acuannya.

| Enum | Nilai → Label → Warna |
|---|---|
| `OrderStatus` | `open` → Open bill → info · `completed` → Selesai → success · `voided` → Void → danger |
| `ShiftStatus` | `open` → Berjalan → info · `closed` → Ditutup → gray · `force_closed` → Ditutup paksa → warning |
| `PaymentStatus` | `paid` → Lunas → success · `voided` → Void → danger |
| `PaymentCategory` | `cash` → Tunai · `qris` → QRIS · `transfer` → Transfer · `debit` → Debit · `credit` → Kredit — semua gray |
| `OrderType` | `takeaway` → Bawa pulang · `dine_in` → Makan di tempat — gray |
| `TenantStatus` | `trial` → Trial → warning · `active` → Aktif → success · `suspended` → Ditangguhkan → danger |
| `UserRole` | `owner` → Owner → primary · `manager` → Manager → info · `supervisor` → Supervisor → gray · `cashier` → Kasir → gray |
| Aktif (bool) | Aktif → success · Nonaktif → gray |
| Stok | ≤ 0 → Habis → danger · ≤ ambang → Menipis → warning |

> Nilai enum mengikuti keputusan Q7 di `docs/SPEC.md` (bagian Database).

Aturan warna: **success** = beres, **warning** = perlu perhatian, **danger** = gagal/dibatalkan,
**info** = sedang berjalan, **gray** = informasi netral. Jangan memakai warna lain.

## Aksesibilitas

- Makna tidak boleh hanya dari warna: label teks selalu ada.
- Kontras teks pill terhadap latar ≥ 4.5:1 (sudah terpenuhi oleh palet di atas).

## Implementasi

```php
// Enum — satu-satunya sumber label & warna
enum TenantStatus: string implements HasLabel, HasColor
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}

// Tabel & infolist cukup:
TextColumn::make('status')->badge();
TextEntry::make('status')->badge();
```

```css
/* components.css — badge bersih: warna & ring tetap bawaan Filament, hanya ukuran yang diatur */
.fi-badge { min-height: 22px; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 500; }
```

## Do / Don't

| Do | Don't |
|---|---|
| Label & warna dari enum | `->color(fn ($state) => match ...)` di setiap Resource |
| Teks singkat 1–2 kata | Kalimat di dalam badge |
| Gray untuk kategori netral | Warna berbeda untuk tiap metode bayar |
