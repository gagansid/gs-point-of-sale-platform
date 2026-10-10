<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SalesLead;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

/*
 * Audit pembuat & pengubah (RecordsAuthor) dan tampilannya (AuditInfo).
 */
beforeEach(function () {
    $outlet = Outlet::factory()->create();
    TenantContext::set($outlet->tenant_id);
    $this->owner = User::factory()->owner()->create(['name' => 'Owner Satu']);
    $this->manager = User::factory()->manager()->create(['name' => 'Manager Dua']);
});

it('mencatat pembuat dan pengubah dari user login', function () {
    $this->actingAs($this->owner);
    $category = Category::factory()->create();

    $this->actingAs($this->manager);
    $category->update(['name' => 'Minuman dingin']);

    expect($category->fresh()->created_by)->toBe($this->owner->id)
        ->and($category->fresh()->updated_by)->toBe($this->manager->id)
        ->and($category->fresh()->authorName('created_by'))->toBe('Owner Satu')
        ->and($category->fresh()->authorName('updated_by'))->toBe('Manager Dua');
});

it('perubahan tanpa user login (sistem/seeder) tidak menghapus pengubah sebelumnya', function () {
    $this->actingAs($this->owner);
    $category = Category::factory()->create();
    auth()->logout();

    $category->update(['name' => 'Diubah sistem']);

    expect($category->fresh()->updated_by)->toBe($this->owner->id);
});

it('data platform mencatat super admin sebagai pelaku', function () {
    $admin = Admin::factory()->create(['name' => 'Admin gs']);
    $this->actingAs($admin, 'admin');

    $lead = SalesLead::factory()->create();

    expect($lead->created_by)->toBe($admin->id)->and($lead->authorName('created_by'))->toBe('Admin gs');
});

it('kartu Riwayat menampilkan pelaku dan tanggal asli bila diubah lebih dari 1 hari lalu', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
    FilamentTimezone::set('Asia/Jakarta');

    $this->travelTo('2026-10-01 03:00:00');
    $product = Product::factory()->create();
    $this->travelTo('2026-10-02 03:00:00');
    $this->actingAs($this->manager);
    $product->update(['name' => 'Kopi Baru']);
    $this->travelTo('2026-10-10 03:00:00');
    $this->actingAs($this->owner);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertSee('oleh Owner Satu')
        ->assertSee('oleh Manager Dua')
        ->assertSee('2 Okt 2026, 10.00');
});
