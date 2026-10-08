<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Illuminate\Support\Facades\Context;

it('menyimpan tenant aktif dan mencatatnya di Context log', function () {
    expect(TenantContext::check())->toBeFalse();

    TenantContext::set('tenant-a');

    expect(TenantContext::id())->toBe('tenant-a')
        ->and(Context::get('tenant_id'))->toBe('tenant-a');

    TenantContext::forget();

    expect(TenantContext::id())->toBeNull()
        ->and(Context::has('tenant_id'))->toBeFalse();
});

it('gagal keras jika kode bisnis berjalan tanpa tenant', function () {
    TenantContext::idOrFail();
})->throws(LogicException::class);

it('run() memulihkan tenant sebelumnya, juga saat terjadi error', function () {
    TenantContext::set('tenant-a');

    $inside = TenantContext::run('tenant-b', fn () => TenantContext::id());
    expect($inside)->toBe('tenant-b')->and(TenantContext::id())->toBe('tenant-a');

    try {
        TenantContext::run('tenant-b', fn () => throw new RuntimeException);
    } catch (RuntimeException) {
    }
    expect(TenantContext::id())->toBe('tenant-a');
});

it('direset untuk setiap request/job baru (scoped)', function () {
    TenantContext::set('tenant-a');

    app()->forgetScopedInstances();

    expect(TenantContext::id())->toBeNull();
});

it('dipulihkan di job queue dari Context', function () {
    TenantContext::set('tenant-a');
    $payload = Context::dehydrate();

    // Simulasi worker: proses baru tanpa tenant
    Context::flush();
    app()->forgetScopedInstances();
    expect(TenantContext::id())->toBeNull();

    Context::hydrate($payload);

    expect(TenantContext::id())->toBe('tenant-a');
});
