<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;

it('owner & manager bisa membuka dashboard', function (string $role) {
    $outlet = Outlet::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $user = User::factory()->{$role}()->forOutlet($outlet)->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/dashboard/products')->assertOk();
})->with(['owner', 'manager']);

it('kasir tidak bisa membuka dashboard', function () {
    $this->actingAs(User::factory()->cashier()->create())->get('/dashboard')->assertForbidden();
});

it('supervisor hanya Laporan: beranda dialihkan, menu lain tertutup (Q50)', function () {
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)->get('/dashboard')->assertRedirect('/dashboard/reports');
    $this->actingAs($supervisor)->get('/dashboard/reports')->assertOk()->assertSee('Laporan')
        // Menu lain tidak ada di sidebar
        ->assertDontSee('/dashboard/orders"', false)->assertDontSee('/dashboard/shifts"', false)->assertDontSee('/dashboard/products"', false);

    foreach (['/dashboard/orders', '/dashboard/shifts', '/dashboard/products', '/dashboard/employees'] as $url) {
        expect($this->actingAs($supervisor)->get($url)->status())->toBeIn([403, 404], $url);
    }
});

it('tamu diarahkan ke login dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/dashboard/login');
});

it('super admin tidak bisa masuk dashboard tenant', function () {
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin')
        ->get('/dashboard')
        ->assertRedirect('/dashboard/login');
});

it('tenant ditangguhkan: dikeluarkan dan diarahkan ke login', function () {
    $owner = User::factory()->owner()->create();
    Tenant::query()->whereKey($owner->tenant_id)->update(['status' => 'suspended']);

    $this->actingAs($owner)->get('/dashboard/products')->assertRedirect('/dashboard/login');
    $this->assertGuest();
});
