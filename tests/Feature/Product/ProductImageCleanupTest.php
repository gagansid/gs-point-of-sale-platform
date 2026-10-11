<?php

declare(strict_types=1);

use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveProduct;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/** T2: gambar produk lama dihapus dari storage saat diganti/dikosongkan, dengan pengaman. */
beforeEach(function () {
    Storage::fake('public');
    $this->pos = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    $this->dir = 'products/'.$this->pos->tenantId;
});

function saveWithImage(stdClass $pos, Product $product, ?string $path): Product
{
    return app(SaveProduct::class)->handle($product, ProductData::fromArray([
        'name' => $product->name, 'price' => $product->price, 'category_id' => $product->category_id,
        'image_path' => $path,
    ]), $pos->outlet)['product'];
}

it('ganti gambar: file lama dihapus, file baru tetap', function () {
    Storage::disk('public')->put($old = $this->dir.'/lama.webp', 'x');
    Storage::disk('public')->put($new = $this->dir.'/baru.webp', 'y');
    $this->pos->croissant->forceFill(['image_path' => $old])->save();

    saveWithImage($this->pos, $this->pos->croissant, $new);

    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($new);
});

it('gambar dikosongkan: file dihapus; field tidak dikirim (API): file tetap', function () {
    Storage::disk('public')->put($old = $this->dir.'/lama.webp', 'x');
    $this->pos->croissant->forceFill(['image_path' => $old])->save();

    app(SaveProduct::class)->handle($this->pos->croissant, ProductData::fromArray([
        'name' => 'Croissant Baru', 'price' => '25000',
    ]), $this->pos->outlet);
    Storage::disk('public')->assertExists($old);

    saveWithImage($this->pos, $this->pos->croissant->fresh(), null);
    Storage::disk('public')->assertMissing($old);
});

it('tidak menghapus file yang masih dipakai produk lain atau di luar folder tenant', function () {
    Storage::disk('public')->put($shared = $this->dir.'/bersama.webp', 'x');
    Storage::disk('public')->put($foreign = 'products/tenant-lain/a.webp', 'x');
    $this->pos->croissant->forceFill(['image_path' => $shared])->save();
    $this->pos->coffee->forceFill(['image_path' => $shared])->save();

    saveWithImage($this->pos, $this->pos->croissant, null);
    Storage::disk('public')->assertExists($shared);

    $this->pos->croissant->forceFill(['image_path' => $foreign])->save();
    saveWithImage($this->pos, $this->pos->croissant->fresh(), null);
    Storage::disk('public')->assertExists($foreign);
});
