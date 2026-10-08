<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantFixture;

beforeEach(function () {
    TenantFixture::createTable();
    $this->mine = TenantContext::run('tenant-a', fn () => TenantFixture::query()->create(['name' => 'Milik A']));
    $this->other = TenantContext::run('tenant-b', fn () => TenantFixture::query()->create(['name' => 'Milik B']));

    Route::middleware(['api', 'tenant'])->prefix('api/_test')->group(function () {
        Route::get('context', fn () => ApiResponse::success(['tenant_id' => TenantContext::id()]));
        // Route model binding harus sudah melihat tenant context
        Route::get('items/{item}', fn (TenantFixture $item) => ApiResponse::success(['name' => $item->name]));
    });
});

function tenantUser(?string $tenantId): User
{
    return (new User)->forceFill(['id' => 1, 'tenant_id' => $tenantId]);
}

it('mengisi tenant context dari user yang login', function () {
    $this->actingAs(tenantUser('tenant-a'))
        ->getJson('/api/_test/context')
        ->assertOk()
        ->assertJsonPath('data.tenant_id', 'tenant-a');
});

it('route model binding hanya menemukan data tenant sendiri', function () {
    $this->actingAs(tenantUser('tenant-a'))
        ->getJson("/api/_test/items/{$this->mine->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Milik A');
});

it('tenant A mendapat 404 saat mengakses data tenant B (IDOR)', function () {
    $this->actingAs(tenantUser('tenant-a'))
        ->getJson("/api/_test/items/{$this->other->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('menolak user tanpa tenant', function () {
    $this->actingAs(tenantUser(null))
        ->getJson('/api/_test/context')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('tanpa login tidak ada tenant context dan data tidak terbaca', function () {
    $this->getJson('/api/_test/context')->assertJsonPath('data.tenant_id', null);
    $this->getJson("/api/_test/items/{$this->mine->id}")->assertNotFound();
});
