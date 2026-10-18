<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Enums\ProductKind;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Member;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quota;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Kuota paket langganan (ADR 0025 B1): batas efektif per tenant & pemakaian saat ini. Satu pintu pengecekan untuk
 * Action yang MEMBUAT atau MENGAKTIFKAN data. Lewat batas setelah downgrade: data lama tetap jalan, hanya tambah
 * yang ditolak.
 */
final class Quotas
{
    public const OUTLETS = 'outlets';

    public const USERS = 'users';

    public const DEVICES = 'devices';

    public const PRODUCTS = 'products';

    public const MEMBERS = 'members';

    /**
     * Tolak bila pemakaian + $adding melewati batas. Wajib di dalam transaksi: baris tenant dikunci agar dua request
     * bersamaan tidak sama-sama lolos (penambahan data per tenant diserialkan sampai commit).
     *
     * @throws BusinessException PLAN_LIMIT_REACHED (OUTLET_LIMIT_REACHED untuk outlet, kompatibel app lama)
     */
    public static function ensureAvailable(string $tenantId, string $quota, int $adding = 1): void
    {
        if ($adding < 1 || Edition::isSelfHosted()) {
            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quotas::ensureAvailable harus dipanggil di dalam transaksi');
        }

        $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
        $limit = $tenant->limit($quota);
        if ($limit === null) {
            return;
        }

        $used = self::used($tenantId, $quota);
        if ($used + $adding <= $limit) {
            return;
        }

        if ($quota === self::OUTLETS) {
            throw BusinessException::of(ErrorCode::OutletLimitReached, details: ['max_outlets' => $limit, 'quota' => $quota, 'limit' => $limit, 'used' => $used]);
        }

        $name = mb_strtolower(Quota::options()[$quota] ?? $quota);
        throw BusinessException::of(
            ErrorCode::PlanLimitReached,
            "Kuota {$name} paket langganan sudah habis ({$used} dari {$limit}). Upgrade paket atau tambah add-on untuk menambah kuota",
            ['quota' => $quota, 'limit' => $limit, 'used' => $used],
        );
    }

    /**
     * Aturan validasi input batas (form paket & batas khusus tenant): {key: int|null}, key harus ada di master data.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $field): array
    {
        return [
            $field => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                $unknown = array_diff(array_keys((array) $value), array_keys(Quota::options()));
                if ($unknown !== []) {
                    $fail('Kuota tidak dikenal: '.implode(', ', $unknown));
                }
            }],
            "{$field}.*" => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * Buang batas kosong (= tanpa batas), urut sesuai master data.
     *
     * @param  array<string, mixed>|null  $limits
     * @return array<string, int>
     */
    public static function normalize(?array $limits): array
    {
        $result = [];
        foreach (array_keys(Quota::options()) as $key) {
            $value = $limits[$key] ?? null;
            if ($value !== null && $value !== '') {
                $result[$key] = (int) $value;
            }
        }

        return $result;
    }

    /**
     * Batas & pemakaian per kuota master data (halaman Langganan, detail bisnis admin, auth/me). $limitedOnly: hanya
     * kuota yang punya batas (tanpa query hitung untuk yang tanpa batas) — dipakai banner tiap halaman.
     *
     * @return list<array{key: string, name: string, limit: int|null, used: int}>
     */
    public static function usage(Tenant $tenant, bool $limitedOnly = false): array
    {
        $rows = [];
        foreach (Quota::options() as $key => $name) {
            $limit = $tenant->limit($key);
            if ($limitedOnly && $limit === null) {
                continue;
            }

            $rows[] = ['key' => $key, 'name' => $name, 'limit' => $limit, 'used' => self::used($tenant->id, $key)];
        }

        return $rows;
    }

    /**
     * Kuota yang pemakaiannya MELEBIHI batas (mis. setelah downgrade): data lama tetap jalan, tambah ditolak.
     *
     * @return list<array{key: string, name: string, limit: int|null, used: int}>
     */
    public static function exceeded(Tenant $tenant): array
    {
        return array_values(array_filter(self::usage($tenant, limitedOnly: true), fn (array $row): bool => $row['used'] > (int) $row['limit']));
    }

    /** Produk ini ikut dihitung kuota "products": aktif dan bukan bahan baku. */
    public static function countsAsProduct(Product $product): bool
    {
        return (bool) $product->is_active && $product->kind !== ProductKind::Ingredient;
    }

    /** Pemakaian saat ini (lintas outlet, hanya tenant ini). Key tanpa penghitung = 0 (tidak dibatasi). */
    public static function used(string $tenantId, string $quota): int
    {
        return match ($quota) {
            self::OUTLETS => Outlet::forTenant($tenantId)->where('is_active', true)->count(),
            self::USERS => User::forTenant($tenantId)->where('is_active', true)->count(),
            self::DEVICES => Device::forTenant($tenantId)->whereNull('revoked_at')->count(),
            // Bahan baku bukan produk jualan (ADR 0020): tidak dihitung
            self::PRODUCTS => Product::forTenant($tenantId)->where('is_active', true)->where('kind', '!=', ProductKind::Ingredient)->count(),
            self::MEMBERS => Member::forTenant($tenantId)->where('is_active', true)->count(),
            default => 0,
        };
    }
}
