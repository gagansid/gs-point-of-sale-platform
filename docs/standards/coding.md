# Standar Penulisan Kode (PHP / Laravel)

Berlaku untuk semua kode di `app/`, `database/`, `routes/`, `tests/`.
Format otomatis oleh **Laravel Pint** (preset `laravel`), dicek **Larastan level 6+**.

## 1. Aturan umum

- `declare(strict_types=1);` di setiap file PHP baru.
- Kelas yang tidak dirancang untuk diturunkan: `final`. Properti yang tidak berubah: `readonly`.
- Tipe wajib di parameter, return, dan properti. Array dijelaskan dengan PHPDoc generik
  (`@param array<int, OrderItemData> $items`, `@return Collection<int, Order>`).
- Nama kode dalam **Bahasa Inggris**; komentar, pesan error, dan label UI dalam **Bahasa Indonesia**.
- Komentar menjelaskan **mengapa**, bukan apa. Wajib pada: rumus perhitungan, penguncian
  (`lockForUpdate`), idempotency, dan workaround.
- Tidak ada `dd()`, `dump()`, `ray()`, `var_dump()` di commit.
- Konstanta ajaib → `config/pos.php` atau Enum.

## 2. Action

Satu kelas = satu use case = satu transaksi DB. Dipanggil dari controller API **dan** Filament.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderCalculator;
use Illuminate\Support\Facades\DB;

final class VoidOrder
{
    public function __construct(
        private readonly VerifyApproval $verifyApproval,
    ) {}

    /**
     * Membatalkan order dan mengembalikan stok.
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Order $order, string $reason, ?ApprovalData $approval = null): Order
    {
        return DB::transaction(function () use ($actor, $order, $reason, $approval): Order {
            // Kunci baris agar dua void bersamaan tidak mengembalikan stok dua kali
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->shift->isOpen()) {
                throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift sudah ditutup');
            }

            $approverId = $this->verifyApproval->handle($actor, 'order.void', $approval);

            // ... kembalikan stok, ubah status, simpan voided_by & approved_by

            return $order->refresh();
        });
    }
}
```

Aturan Action:

| Aturan | Keterangan |
|---|---|
| Method publik tunggal `handle()` | Argumen eksplisit (model, DTO), bukan `Request` |
| Selalu `DB::transaction` | Untuk Action yang menulis data |
| Otorisasi bisnis di dalam Action | Permission dasar boleh di FormRequest/Policy, aturan PIN approval di Action |
| Error → `BusinessException` | Kode dari `ErrorCode`, pesan Bahasa Indonesia |
| Action boleh memanggil Action lain | Via constructor injection; transaksi bersarang aman di Laravel |
| Idempotency di Action | Cek ID yang sudah ada **di awal**, kembalikan hasil lama |

## 3. Data masukan (DTO)

Untuk input > 3 field, gunakan objek data `final readonly` di namespace Action terkait
(mis. `App\Actions\Order\Data\CheckoutData`), dibuat dari `FormRequest::toData()` atau dari form Filament.
Tidak menambah package (tidak memakai `spatie/laravel-data` di MVP).

```php
final readonly class CheckoutData
{
    /** @param array<int, CheckoutItemData> $items */
    public function __construct(
        public string $id,
        public OrderType $orderType,
        public array $items,
        public array $payments,
        public ?DiscountData $discount = null,
        public ?string $tableLabel = null,
    ) {}
}
```

## 4. Uang

- Kolom DB: `DECIMAL(15,2)`. Cast model: `'decimal:2'` (menghasilkan string).
- Perhitungan memakai `App\Support\Money` (berbasis `bcmath`, skala 2). **Dilarang** `float`,
  `round()` atau operator aritmatika langsung pada nominal.
- Output JSON: string `"62000.00"`. Output UI: `Rp62.000` (lihat `docs/standards/ui/content.md`).

## 5. Enum

Semua nilai tetap adalah backed enum `string`. Enum yang tampil di UI mengimplementasikan kontrak
Filament agar label & warna hanya didefinisikan sekali:

```php
enum OrderStatus: string implements HasLabel, HasColor
{
    case Open = 'open';
    case Completed = 'completed';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open bill',
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Completed => 'success',
            self::Voided => 'danger',
        };
    }
}
```

## 6. Model

- `use HasUuids, BelongsToTenant;` (+ `SoftDeletes` untuk tabel master).
- `$fillable` eksplisit (tidak memakai `$guarded = []`).
- Cast enum, decimal, boolean, datetime, JSON di method `casts()`.
- Relasi bertipe return (`: BelongsTo`, `: HasMany`) dengan generik PHPDoc.
- Scope lokal untuk filter yang dipakai ulang: `scopeOpen()`, `scopeForShift()`.
- Hindari `$appends` dan accessor yang memicu query.

## 7. Query

- Selalu eager load relasi yang dipakai di Resource (`->with([...])`) — hindari N+1.
  Aktifkan `Model::preventLazyLoading()` di non-production.
- `lockForUpdate()` untuk: nomor order, stok, saldo order saat tambah payment, void.
- Query lintas tenant (`Model::allTenants()` / `Model::forTenant($id)`) **hanya** di `app/Filament/Admin`
  dan command sistem, dengan komentar alasannya. Seeder/command lain memakai `TenantContext::run()`.
- Pagination: `->paginate(min($perPage, 100))`.

## 8. Konfigurasi & secret

- Baca konfigurasi lewat `config('pos.xxx')`, tidak memanggil `env()` di luar `config/`.
- Secret hanya di `.env`; contoh nilai di `.env.example`.

## 9. Logging

- `Log::info/warning/error` dengan konteks array; `request_id` otomatis ikut via `Context`.
- Jangan me-log PIN, password, token, atau payload pembayaran lengkap.
