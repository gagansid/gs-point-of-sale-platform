<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gambar produk demo (ilustrasi tema gs.POS, WebP 800×800 ±10 KB) dari resources/images/products.
 * Hanya mengisi produk demo yang belum punya gambar, jadi aman dijalankan ulang di DB yang sudah ada:
 * php artisan db:seed --class=DemoProductImagesSeeder
 */
final class DemoProductImagesSeeder extends Seeder
{
    public const TENANT_SLUG = 'kopi-senja-demo';

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if ($tenant === null) {
            return;
        }

        TenantContext::run($tenant->id, function () use ($tenant): void {
            Product::query()->whereNull('image_path')->get()->each(function (Product $product) use ($tenant): void {
                $source = resource_path('images/products/'.Str::slug($product->name).'.webp');

                if (! is_file($source)) {
                    return;
                }

                // Lokasi sama dengan unggahan dari form produk: products/{tenant}/...
                $path = 'products/'.$tenant->id.'/'.Str::slug($product->name).'-'.Str::lower(Str::random(6)).'.webp';
                Storage::disk('public')->put($path, (string) file_get_contents($source));
                $product->forceFill(['image_path' => $path])->save();
            });
        });
    }
}
