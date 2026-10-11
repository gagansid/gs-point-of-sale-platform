<?php

declare(strict_types=1);

use App\Actions\User\UpdateOwnProfile;
use App\Filament\Dashboard\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Dashboard\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Riwayat perubahan / audit log (SPEC Q58, R7). */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create(['name' => 'Rina']);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
    $this->product = Product::factory()->create(['name' => 'Croissant', 'price' => '24000.00']);
});

it('ubah harga tercatat: pelaku, nilai lama → baru, hanya kolom yang berubah', function () {
    $this->product->update(['price' => '26000', 'name' => 'Croissant']);

    $log = ActivityLog::query()->where('subject_id', $this->product->id)->where('event', 'updated')->sole();
    expect($log->causer?->is($this->owner))->toBeTrue()
        ->and($log->tenant_id)->toBe($this->outlet->tenant_id)
        ->and($log->properties['old'])->toBe(['price' => '24000.00'])
        ->and($log->properties['attributes'])->toBe(['price' => '26000.00'])
        ->and(ActivityLogResource::summary($log))->toBe('Harga: Rp24.000 → Rp26.000');
});

it('kata sandi & PIN tidak pernah tersimpan; hanya dicatat "diganti"', function () {
    $employee = User::factory()->cashier()->create(['name' => 'Budi']);
    $employee->update(['password' => 'rahasia-baru-123', 'pin' => '481920', 'name' => 'Budi S']);

    $logs = ActivityLog::query()->where('subject_id', $employee->id)->get();
    $raw = $logs->pluck('properties')->toJson();

    expect($raw)->not->toContain('rahasia-baru-123')->not->toContain('481920')->not->toContain('$2y$')
        ->and($logs->firstWhere('description', 'credentials')?->properties['secrets'])->toBe(['password', 'pin'])
        ->and(ActivityLogResource::summary($logs->firstWhere('description', 'credentials')))->toBe('Kata sandi & PIN diganti');
});

it('halaman Riwayat perubahan menampilkan perubahan dengan nama pelaku', function () {
    $this->product->update(['price' => '26000']);
    app(UpdateOwnProfile::class)->handle($this->owner, 'Rina', (string) $this->owner->email, null, null, false);

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords(ActivityLog::query()->where('subject_id', $this->product->id)->get())
        ->assertSee('Harga: Rp24.000 → Rp26.000')
        ->assertSee('Rina')
        ->assertSee('Croissant');

    $this->get(ActivityLogResource::getUrl())->assertOk()->assertSee('Riwayat perubahan');
});

it('isolasi tenant: perubahan tenant lain tidak terlihat', function () {
    TenantContext::forget();
    $otherOutlet = Outlet::factory()->create();
    TenantContext::run($otherOutlet->tenant_id, fn () => Product::factory()->create(['name' => 'Produk Tetangga'])->update(['price' => '1']));
    TenantContext::set($this->outlet->tenant_id);

    expect(ActivityLog::query()->get()->pluck('tenant_id')->unique()->all())->toBe([$this->outlet->tenant_id]);
    Livewire::test(ListActivityLogs::class)->assertDontSee('Produk Tetangga');
});

it('permission: kasir & supervisor ditolak; manager hanya melihat katalog', function () {
    $this->product->update(['price' => '26000']);
    $this->outlet->update(['tax_rate' => '10']);
    $catalogLog = ActivityLog::query()->where('subject_type', Product::class)->get();
    $outletLog = ActivityLog::query()->where('subject_type', Outlet::class)->get();

    foreach ([User::factory()->cashier()->create(), User::factory()->supervisor()->create()] as $user) {
        $this->actingAs($user);
        expect(ActivityLogResource::canViewAny())->toBeFalse();
        $this->get(ActivityLogResource::getUrl())->assertForbidden();
    }

    $this->actingAs(User::factory()->manager()->forOutlet($this->outlet)->create());
    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords($catalogLog)
        ->assertCanNotSeeTableRecords($outletLog);
});

it('perubahan tanpa tenant context (mis. panel admin) tetap tersimpan ke tenant datanya', function () {
    TenantContext::forget();
    Product::allTenants()->whereKey($this->product->id)->firstOrFail()->update(['price' => '30000']);
    TenantContext::set($this->outlet->tenant_id);

    expect(ActivityLog::query()->where('subject_id', $this->product->id)->where('event', 'updated')->value('tenant_id'))
        ->toBe($this->outlet->tenant_id);
});

it('simpan tanpa perubahan tidak membuat log', function () {
    $before = ActivityLog::query()->count();
    $this->product->update(['price' => '24000.00']);

    expect(ActivityLog::query()->count())->toBe($before);
});
