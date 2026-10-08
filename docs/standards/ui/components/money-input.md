# Money Input & Money Column

**Tujuan:** memasukkan dan menampilkan nominal Rupiah tanpa salah ketik dan tanpa `float`.

## Kapan dipakai

- Semua field dan kolom bertipe uang: harga, harga modal, kas awal/aktual, `price_delta`, total.
- **Bukan** untuk persen (pajak, service) — pakai `TextInput::numeric()->suffix('%')`.

## Anatomi

```
Harga jual *
[ Rp │ 62.000                       ]
```

Prefix `Rp` abu, angka rata kanan, pemisah ribuan titik saat mengetik.

## Varian

| Komponen | Lokasi | Kapan |
|---|---|---|
| `MoneyInput` | `app/Filament/Shared/Forms/MoneyInput.php` | Field form |
| `MoneyColumn` | `app/Filament/Shared/Columns/MoneyColumn.php` | Kolom tabel |
| `MoneyColumn::signed()` | sama | Selisih kas, penyesuaian (negatif merah, positif hijau) |
| `MoneyEntry` | `app/Filament/Shared/Infolists/MoneyEntry.php` | Infolist detail |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Prefix | `Rp`, warna `text-muted` |
| Perataan | Kanan |
| Angka | `tabular-nums` |
| Desimal | Tidak ditampilkan bila `,00`; maks. 2 digit |
| Nilai dikirim ke server | String desimal (`"62000.00"`) |
| Batas | `minValue(0)`, maks. `9999999999999.99` (DECIMAL 15,2) |

## Perilaku & state

- Mask ribuan saat mengetik (`->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))`),
  dibersihkan menjadi string desimal saat `dehydrate`.
- Nilai negatif hanya untuk field yang memang boleh (penyesuaian), `->allowNegative()`.
- Kolom total di footer tabel memakai `->summarize(Sum::make()->money('IDR', locale: 'id'))`.

## Aksesibilitas

- Prefix `Rp` bukan bagian dari label; label tetap "Harga jual".
- Screen reader membaca nilai lengkap (tanpa singkatan "rb", "jt").

## Implementasi

> **Jangan memakai `stripCharacters('.')` untuk uang.** Di Filament v5 cast-nya juga berjalan saat
> data dimuat: `"22000.00"` dari database menjadi `"2200000"` dan **harga tersimpan ×100** saat form
> disimpan. Pakai `MoneyStateCast` (dikunci oleh test regresi `ProductResourceTest`).

```php
final class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix('Rp')
            ->inputMode('numeric')
            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
            ->stateCast(new MoneyStateCast)   // "22000.00" ⇄ "22.000"
            ->extraInputAttributes(['class' => 'text-right tabular-nums'])
            ->rule('decimal:0,2')
            ->rule('min:0');
    }

    // Validasi memakai nilai yang sudah dinormalkan, bukan teks bermask
    public function mutateStateForValidation(mixed $state): mixed
    {
        return parent::mutateStateForValidation((new MoneyStateCast)->get($state));
    }
}

final class MoneyColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->money('IDR', locale: 'id')
            ->alignEnd()
            ->extraAttributes(['class' => 'is-money']);
    }
}
```

## Do / Don't

| Do | Don't |
|---|---|
| Selalu `MoneyInput` / `MoneyColumn` | `TextInput::numeric()` biasa untuk uang |
| Rata kanan, tabular | Rata kiri / font proporsional |
| `Rp62.000` | `Rp 62,000.00`, `62000`, `IDR 62.000` |
