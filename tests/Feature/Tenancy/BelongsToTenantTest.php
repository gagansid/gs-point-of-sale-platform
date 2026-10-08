<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\TenantFixture;

beforeEach(function () {
    TenantFixture::createTable();

    // Data dua tenant dibuat dalam konteks masing-masing
    TenantContext::run('tenant-a', function () {
        TenantFixture::query()->create(['name' => 'Kopi A1']);
        TenantFixture::query()->create(['name' => 'Kopi A2']);
    });
    $this->foreign = TenantContext::run('tenant-b', fn () => TenantFixture::query()->create(['name' => 'Kopi B1']));
});

it('mengisi tenant_id otomatis dari tenant aktif', function () {
    TenantContext::set('tenant-a');

    expect(TenantFixture::query()->create(['name' => 'Baru'])->tenant_id)->toBe('tenant-a');
});

it('hanya mengembalikan data tenant aktif', function () {
    TenantContext::set('tenant-a');

    expect(TenantFixture::query()->pluck('name')->all())->toEqualCanonicalizing(['Kopi A1', 'Kopi A2'])
        ->and(TenantFixture::query()->find($this->foreign->id))->toBeNull()
        ->and(TenantFixture::query()->where('name', 'Kopi B1')->exists())->toBeFalse();
});

it('findOrFail data tenant lain menghasilkan not found', function () {
    TenantContext::set('tenant-a');

    TenantFixture::query()->findOrFail($this->foreign->id);
})->throws(ModelNotFoundException::class);

it('update & delete massal hanya menyentuh tenant aktif', function () {
    TenantContext::set('tenant-a');

    TenantFixture::query()->update(['name' => 'Diubah']);
    TenantFixture::query()->delete();

    expect(TenantFixture::forTenant('tenant-b')->value('name'))->toBe('Kopi B1');
});

it('fail-closed: tanpa tenant context tidak ada data yang terbaca', function () {
    expect(TenantFixture::query()->count())->toBe(0)
        ->and(TenantFixture::query()->find($this->foreign->id))->toBeNull();
});

it('menolak membuat data tanpa tenant', function () {
    TenantFixture::query()->create(['name' => 'Yatim']);
})->throws(LogicException::class, 'tanpa tenant_id');

it('menolak membuat data untuk tenant lain dari dalam konteks tenant', function () {
    TenantContext::set('tenant-a');

    // Mis. tenant_id disusupkan lewat input request
    TenantFixture::query()->create(['tenant_id' => 'tenant-b', 'name' => 'Susupan']);
})->throws(LogicException::class, 'tenant lain');

it('menolak memindahkan data ke tenant lain', function () {
    TenantContext::set('tenant-a');
    $item = TenantFixture::query()->firstOrFail();

    $item->update(['tenant_id' => 'tenant-b']);
})->throws(LogicException::class, 'tidak boleh diubah');

it('panel admin bisa membaca lintas tenant secara eksplisit', function () {
    expect(TenantFixture::allTenants()->count())->toBe(3)
        ->and(TenantFixture::forTenant('tenant-b')->count())->toBe(1);
});
