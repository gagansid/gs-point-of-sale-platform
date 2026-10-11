<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Database\Seeders\DemoProductImagesSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Gambar produk demo: hanya produk demo tanpa gambar, file WebP kecil di disk public. */
it('mengisi gambar produk demo yang kosong dan tidak menimpa gambar yang ada', function () {
    Storage::fake('public');
    $outlet = Outlet::factory()->create();
    Tenant::query()->whereKey($outlet->tenant_id)->update(['slug' => DemoProductImagesSeeder::TENANT_SLUG]);

    [$croissant, $custom, $unknown] = TenantContext::run($outlet->tenant_id, fn () => [
        Product::factory()->create(['name' => 'Croissant']),
        Product::factory()->create(['name' => 'Americano', 'image_path' => 'products/x/milik-owner.jpg']),
        Product::factory()->create(['name' => 'Produk Lain']),
    ]);

    (new DemoProductImagesSeeder)->run();
    (new DemoProductImagesSeeder)->run();

    $path = $croissant->fresh()?->image_path;
    expect($path)->toStartWith('products/'.$outlet->tenant_id.'/croissant-')->toEndWith('.webp')
        ->and($custom->fresh()?->image_path)->toBe('products/x/milik-owner.jpg')
        ->and($unknown->fresh()?->image_path)->toBeNull()
        ->and(Storage::disk('public')->size((string) $path))->toBeLessThan(20 * 1024)
        ->and(Storage::disk('public')->files('products/'.$outlet->tenant_id))->toHaveCount(1);
});

it('semua produk di seeder demo punya file gambar', function () {
    foreach (['Es Kopi Susu', 'Americano', 'Caffe Latte', 'Matcha Latte', 'Teh Tarik', 'Croissant', 'Banana Bread'] as $name) {
        expect(resource_path('images/products/'.Str::slug($name).'.webp'))->toBeFile();
    }
});
