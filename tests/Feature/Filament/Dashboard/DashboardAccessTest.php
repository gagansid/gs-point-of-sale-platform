<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;

it('owner & manager bisa membuka dashboard', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/dashboard/products')->assertOk();
})->with(['owner', 'manager']);

it('supervisor & kasir tidak bisa membuka dashboard', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())->get('/dashboard')->assertForbidden();
})->with(['supervisor', 'cashier']);

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
