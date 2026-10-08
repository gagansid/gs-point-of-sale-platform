<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenantId]);
    $this->token = userToken($this->owner);
    [$this->category, $this->size, $this->sugar] = TenantContext::run($this->tenantId, fn () => [
        Category::factory()->create(),
        OptionGroup::factory()->create(['name' => 'Ukuran']),
        OptionGroup::factory()->create(['name' => 'Gula']),
    ]);
});

function productBody(array $override = []): array
{
    return ['name' => 'Es Kopi Susu', 'price' => 22000, 'cost_price' => '8000.50', ...$override];
}

describe('daftar & barcode', function () {
    it('mencari produk berdasarkan nama/SKU/barcode dengan pagination', function () {
        TenantContext::run($this->tenantId, function () {
            Product::factory()->create(['name' => 'Es Kopi Susu', 'sku' => 'KOP-1']);
            Product::factory()->create(['name' => 'Teh Tarik', 'barcode' => '899123']);
            // Nama eksplisit: nama acak factory bisa kebetulan mengandung "kopi"
            foreach (['Croissant', 'Roti Bakar', 'Matcha'] as $name) {
                Product::factory()->create(['name' => $name]);
            }
        });
        Product::factory()->create(['name' => 'Kopi Tenant Lain']);

        $this->withToken($this->token)->getJson('/api/v1/products?search=kopi', apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Es Kopi Susu'])
            ->assertJsonPath('meta.pagination.total', 1);

        freshAuth();
        $this->withToken($this->token)->getJson('/api/v1/products?per_page=2', apiHeaders())
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.last_page', 3);
    });

    it('karakter wildcard di pencarian tidak dianggap pola', function () {
        TenantContext::run($this->tenantId, fn () => Product::factory()->count(2)->create());

        $this->withToken($this->token)->getJson('/api/v1/products?search=%25', apiHeaders())
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('cari via barcode hanya produk aktif tenant sendiri', function () {
        TenantContext::run($this->tenantId, function () {
            Product::factory()->create(['name' => 'Air Mineral', 'barcode' => '8991001']);
            Product::factory()->inactive()->create(['barcode' => '8991002']);
        });
        Product::factory()->create(['barcode' => '8991003']);

        $this->withToken($this->token)->getJson('/api/v1/products/barcode/8991001', apiHeaders())
            ->assertOk()->assertJsonPath('data.name', 'Air Mineral');

        foreach (['8991002', '8991003', 'tidak-ada'] as $code) {
            freshAuth();
            assertApiError($this->withToken($this->token)->getJson("/api/v1/products/barcode/{$code}", apiHeaders()), 'NOT_FOUND', 404);
        }
    });
});

describe('tambah', function () {
    it('menambah produk dengan urutan grup opsi dan stok awal', function () {
        $response = $this->withToken($this->token)->postJson('/api/v1/products', productBody([
            'category_id' => $this->category->id,
            'sku' => 'KOP-01',
            'track_stock' => true,
            'stock_qty' => 24,
            'option_group_ids' => [$this->sugar->id, $this->size->id],
        ]), apiHeaders());

        $response->assertCreated()
            ->assertJsonPath('data.price', '22000.00')
            ->assertJsonPath('data.cost_price', '8000.50')
            ->assertJsonPath('data.stock_qty', 24)
            ->assertJsonPath('data.option_group_ids', [$this->sugar->id, $this->size->id]);

        $product = Product::allTenants()->sole();
        expect($product->tenant_id)->toBe($this->tenantId)
            ->and(StockMovement::allTenants()->sole()->qty_after)->toBe(24);
    });

    it('validasi gagal', function (array $override, string $field) {
        TenantContext::run($this->tenantId, fn () => Product::factory()->create(['sku' => 'DUP-1']));

        $response = $this->withToken($this->token)->postJson('/api/v1/products', productBody($override), apiHeaders());

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor($field, 'error.details');
    })->with([
        'harga negatif' => [['price' => -1], 'price'],
        'harga 3 desimal' => [['price' => '1000.123'], 'price'],
        'harga bukan angka' => [['price' => 'gratis'], 'price'],
        'SKU dobel' => [['sku' => 'DUP-1'], 'sku'],
        'barcode berbahaya' => [['barcode' => "1' OR 1=1"], 'barcode'],
        'kategori tidak ada' => [['category_id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a'], 'category_id'],
    ]);

    it('SKU yang sama boleh dipakai tenant lain', function () {
        Product::factory()->create(['sku' => 'KOP-01']);

        $this->withToken($this->token)->postJson('/api/v1/products', productBody(['sku' => 'KOP-01']), apiHeaders())->assertCreated();
    });

    it('kasir tidak boleh menambah produk', function () {
        $cashier = User::factory()->cashier()->forOutlet($this->outlet)->create();

        assertApiError($this->withToken(userToken($cashier))->postJson('/api/v1/products', productBody(), apiHeaders()), 'FORBIDDEN', 403);
    });

    it('isolasi: kategori & grup opsi tenant lain ditolak', function () {
        $foreignCategory = Category::factory()->create();
        $foreignGroup = OptionGroup::factory()->create();

        $response = $this->withToken($this->token)->postJson('/api/v1/products', productBody([
            'category_id' => $foreignCategory->id,
            'option_group_ids' => [$foreignGroup->id],
        ]), apiHeaders());

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor('category_id', 'error.details')
            ->assertJsonValidationErrorFor('option_group_ids.0', 'error.details');
    });

    it('request ganda dengan id yang sama tetap satu produk', function () {
        $body = productBody(['id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6b', 'track_stock' => true, 'stock_qty' => 5]);

        $this->withToken($this->token)->postJson('/api/v1/products', $body, apiHeaders())->assertCreated();
        freshAuth();
        $this->withToken($this->token)->postJson('/api/v1/products', $body, apiHeaders())
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        expect(Product::allTenants()->count())->toBe(1)
            ->and(StockMovement::allTenants()->count())->toBe(1);
    });
});

describe('ubah & hapus', function () {
    beforeEach(function () {
        $this->product = TenantContext::run($this->tenantId, function () {
            $product = Product::factory()->tracked(10)->create(['name' => 'Latte', 'sku' => 'LAT-1']);
            $product->optionGroups()->attach($this->size->id, ['sort_order' => 0]);

            return $product;
        });
    });

    it('mengubah sebagian field tanpa mengubah stok & grup opsi yang tidak dikirim', function () {
        $this->withToken($this->token)->putJson("/api/v1/products/{$this->product->id}", ['name' => 'Caffe Latte', 'price' => '28000', 'sku' => 'LAT-1'], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.name', 'Caffe Latte')
            ->assertJsonPath('data.price', '28000.00')
            ->assertJsonPath('data.stock_qty', 10)
            ->assertJsonPath('data.track_stock', true)
            ->assertJsonPath('data.option_group_ids', [$this->size->id]);
    });

    it('stok tidak bisa diubah lewat endpoint ubah produk', function () {
        assertApiError(
            $this->withToken($this->token)->putJson("/api/v1/products/{$this->product->id}", productBody(['stock_qty' => 999]), apiHeaders()),
            'VALIDATION_ERROR',
            422,
        );
    });

    it('isolasi: produk tenant lain → 404', function () {
        $foreign = Product::factory()->create();

        assertApiError($this->withToken($this->token)->putJson("/api/v1/products/{$foreign->id}", productBody(), apiHeaders()), 'NOT_FOUND', 404);
        freshAuth();
        assertApiError($this->withToken($this->token)->deleteJson("/api/v1/products/{$foreign->id}", [], apiHeaders()), 'NOT_FOUND', 404);
    });

    it('menghapus produk (soft delete)', function () {
        $this->withToken($this->token)->deleteJson("/api/v1/products/{$this->product->id}", [], apiHeaders())->assertOk();

        expect(Product::allTenants()->find($this->product->id))->toBeNull()
            ->and(Product::allTenants()->withTrashed()->find($this->product->id))->not->toBeNull();
    });
});
