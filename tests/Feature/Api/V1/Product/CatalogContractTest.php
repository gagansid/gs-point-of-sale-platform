<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\User;
use App\Support\TenantContext;

/*
 * Kontrak katalog (fase 2): permission ubah/hapus produk, kategori, grup opsi, dan isolasi tenant
 * pada daftar produk, metode bayar, serta katalog. Semua aksi tulis memerlukan product.manage
 * (owner/manager); GET /products tidak memerlukan permission (semua role boleh membaca katalog).
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create());
    $this->ownerToken = userToken($this->owner);
    [$this->category, $this->group] = TenantContext::run($this->pos->tenantId, fn () => [
        Category::factory()->create(['name' => 'Kopi']),
        OptionGroup::factory()->create(['name' => 'Gula']),
    ]);
    TenantContext::forget();
});

/** Body grup opsi yang valid untuk PUT /option-groups/{id}. */
function catalogContractGroupBody(array $override = []): array
{
    return [
        'name' => 'Gula',
        'min_select' => 0,
        'max_select' => 1,
        'options' => [['name' => 'Normal', 'price_delta' => 0]],
        ...$override,
    ];
}

describe('PUT /products/{id}', function () {
    it('menolak kasir mengubah produk', function () {
        assertApiError(
            $this->withToken($this->pos->token)->putJson("/api/v1/products/{$this->pos->coffee->id}", ['name' => 'Bajak', 'price' => 1000], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->pos->coffee->refresh()->name)->toBe('Es Kopi Susu');
    });
});

describe('DELETE /products/{id}', function () {
    it('menolak kasir menghapus produk', function () {
        assertApiError(
            $this->withToken($this->pos->token)->deleteJson("/api/v1/products/{$this->pos->coffee->id}", [], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->pos->coffee->refresh()->trashed())->toBeFalse();
    });
});

describe('GET /products', function () {
    // Kontrak: index tanpa cek permission; role dengan permission paling sedikit (kasir) tetap boleh membaca
    it('mengizinkan kasir membaca daftar produk (tanpa permission khusus)', function () {
        $this->withToken($this->pos->token)->getJson('/api/v1/products', apiHeaders())->assertOk();
    });

    it('tidak menampilkan produk tenant lain', function () {
        $foreign = posSetup(openShift: false);

        freshAuth();
        $ids = $this->withToken($this->pos->token)->getJson('/api/v1/products?per_page=100', apiHeaders())
            ->assertOk()
            ->json('data.*.id');

        expect($ids)->toContain($this->pos->coffee->id)
            ->not->toContain($foreign->coffee->id)
            ->not->toContain($foreign->croissant->id);
    });
});

describe('PUT /categories/{id}', function () {
    it('menolak payload tidak valid', function () {
        assertApiError(
            $this->withToken($this->ownerToken)->putJson("/api/v1/categories/{$this->category->id}", ['name' => ''], apiHeaders()),
            'VALIDATION_ERROR',
            422,
        );
    });

    it('menolak kasir mengubah kategori', function () {
        assertApiError(
            $this->withToken($this->pos->token)->putJson("/api/v1/categories/{$this->category->id}", ['name' => 'Bajak'], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->category->refresh()->name)->toBe('Kopi');
    });
});

describe('DELETE /categories/{id}', function () {
    it('menolak kasir menghapus kategori', function () {
        assertApiError(
            $this->withToken($this->pos->token)->deleteJson("/api/v1/categories/{$this->category->id}", [], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->category->refresh()->trashed())->toBeFalse();
    });

    it('menghapus dua kali: kedua kalinya 404', function () {
        $this->withToken($this->ownerToken)->deleteJson("/api/v1/categories/{$this->category->id}", [], apiHeaders())->assertOk();

        freshAuth();
        assertApiError(
            $this->withToken($this->ownerToken)->deleteJson("/api/v1/categories/{$this->category->id}", [], apiHeaders()),
            'NOT_FOUND',
            404,
        );
    });
});

describe('PUT /option-groups/{id}', function () {
    it('menolak payload tidak valid', function () {
        assertApiError(
            $this->withToken($this->ownerToken)->putJson("/api/v1/option-groups/{$this->group->id}", catalogContractGroupBody(['max_select' => 0]), apiHeaders()),
            'VALIDATION_ERROR',
            422,
        );
    });

    it('menolak kasir mengubah grup opsi', function () {
        assertApiError(
            $this->withToken($this->pos->token)->putJson("/api/v1/option-groups/{$this->group->id}", catalogContractGroupBody(['name' => 'Bajak']), apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->group->refresh()->name)->toBe('Gula');
    });
});

describe('DELETE /option-groups/{id}', function () {
    it('menolak kasir menghapus grup opsi', function () {
        assertApiError(
            $this->withToken($this->pos->token)->deleteJson("/api/v1/option-groups/{$this->group->id}", [], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->group->refresh()->trashed())->toBeFalse();
    });

    it('grup opsi tenant lain → 404 dan tidak terhapus', function () {
        $foreign = posSetup(openShift: false);

        freshAuth();
        assertApiError(
            $this->withToken($this->ownerToken)->deleteJson("/api/v1/option-groups/{$foreign->size->id}", [], apiHeaders()),
            'NOT_FOUND',
            404,
        );

        expect(OptionGroup::allTenants()->find($foreign->size->id))->not->toBeNull();
    });

    it('menghapus dua kali: kedua kalinya 404', function () {
        $this->withToken($this->ownerToken)->deleteJson("/api/v1/option-groups/{$this->group->id}", [], apiHeaders())->assertOk();

        freshAuth();
        assertApiError(
            $this->withToken($this->ownerToken)->deleteJson("/api/v1/option-groups/{$this->group->id}", [], apiHeaders()),
            'NOT_FOUND',
            404,
        );
    });
});

describe('GET /payment-methods', function () {
    it('tidak menampilkan metode bayar tenant lain', function () {
        $foreign = posSetup(openShift: false);

        freshAuth();
        $ids = $this->withToken($this->ownerToken)->getJson('/api/v1/payment-methods', apiHeaders())
            ->assertOk()
            ->json('data.*.id');

        expect($ids)->toEqualCanonicalizing($this->pos->methods->pluck('id')->all())
            ->and(array_intersect($ids, $foreign->methods->pluck('id')->all()))->toBe([]);
    });
});

describe('GET /catalog', function () {
    it('tidak memuat produk, grup opsi, kategori, maupun metode bayar tenant lain', function () {
        $foreign = posSetup(openShift: false);
        $foreignCategory = TenantContext::run($foreign->tenantId, fn () => Category::factory()->create(['name' => 'Kopi']));
        TenantContext::forget();
        // Kategori hanya dikirim bila punya produk yang dijual (Q42)
        $this->pos->coffee->forceFill(['category_id' => $this->category->id])->save();
        $foreign->coffee->forceFill(['category_id' => $foreignCategory->id])->save();

        freshAuth();
        $data = $this->withToken($this->pos->token)->getJson('/api/v1/catalog', apiHeaders())->assertOk()->json('data');

        $productIds = array_column($data['products'], 'id');
        $groupIds = array_column($data['option_groups'], 'id');
        $categoryIds = array_column($data['categories'], 'id');
        $methodIds = array_column($data['payment_methods'], 'id');

        expect($productIds)->toContain($this->pos->coffee->id)
            ->not->toContain($foreign->coffee->id)
            ->not->toContain($foreign->croissant->id)
            ->and($groupIds)->toContain($this->pos->size->id)->not->toContain($foreign->size->id)
            ->and($categoryIds)->toContain($this->category->id)->not->toContain($foreignCategory->id)
            ->and(array_intersect($methodIds, $foreign->methods->pluck('id')->all()))->toBe([]);
    });
});
