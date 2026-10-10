<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Resources\Categories\CategoryResource;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Checklist "Mulai berjualan" di beranda panel pelanggan (onboarding ADR 0009, S5).
 * Langkah tablet/kasir menyusul di fase aplikasi kasir.
 */
final class SetupProgress
{
    /**
     * @return list<array{key: string, label: string, hint: string, done: bool, url: string|null}>
     */
    public static function steps(Tenant $tenant): array
    {
        $outlet = CurrentOutlet::get();

        $steps = [
            [
                'key' => 'outlet',
                'label' => 'Lengkapi profil outlet',
                'hint' => 'Alamat, pajak, service charge, dan pembulatan',
                // Dianggap selesai setelah disimpan sekali (diubah sejak dibuat)
                'done' => $outlet !== null && $outlet->updated_at !== null && $outlet->created_at !== null
                    && $outlet->updated_at->greaterThan($outlet->created_at),
                'url' => OutletSettings::getUrl(),
            ],
            [
                'key' => 'category',
                'label' => 'Buat kategori menu',
                'hint' => 'Mis. Kopi, Non Kopi, Makanan',
                'done' => Category::query()->exists(),
                'url' => CategoryResource::getUrl('index'),
            ],
            [
                'key' => 'product',
                'label' => 'Tambahkan produk',
                'hint' => 'Nama, harga, dan opsi seperti ukuran atau gula',
                'done' => Product::query()->where('is_active', true)->exists(),
                'url' => ProductResource::getUrl('create'),
            ],
        ];

        if ($tenant->needsOwnerEmailVerification()) {
            $steps[] = [
                'key' => 'email',
                'label' => 'Verifikasi email owner',
                'hint' => 'Kasir bisa bertransaksi setelah email terverifikasi',
                'done' => false,
                'url' => null,
            ];
        }

        return $steps;
    }

    public static function isComplete(Tenant $tenant): bool
    {
        return collect(self::steps($tenant))->every(fn (array $step): bool => $step['done']);
    }

    /** Katalog kosong → template menu boleh dipakai. */
    public static function catalogIsEmpty(): bool
    {
        return ! Category::query()->exists() && ! Product::query()->exists();
    }
}
