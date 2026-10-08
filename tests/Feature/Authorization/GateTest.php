<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function userWithRole(?UserRole $role): User
{
    return (new User)->forceFill(['id' => 1, 'role' => $role]);
}

it('memutuskan permission berdasarkan role', function () {
    expect(userWithRole(UserRole::Supervisor)->can('order.void'))->toBeTrue()
        ->and(userWithRole(UserRole::Cashier)->can('order.void'))->toBeFalse()
        ->and(userWithRole(UserRole::Manager)->can('stock.adjust'))->toBeTrue()
        ->and(userWithRole(UserRole::Manager)->can('user.manage'))->toBeFalse()
        ->and(userWithRole(UserRole::Owner)->can('outlet.settings'))->toBeTrue();
});

it('menolak semua permission untuk user tanpa role', function () {
    expect(userWithRole(null)->can('order.create'))->toBeFalse();
});

it('tidak bisa memberi permission lewat definisi Gate lain', function () {
    // Permission hanya dari UserRole; Gate/Policy lain tidak boleh menambah hak kasir
    Gate::define('order.void', fn () => true);

    expect(userWithRole(UserRole::Cashier)->can('order.void'))->toBeFalse();
});

it('owner tidak melewati aturan Policy/Gate untuk ability non-permission', function () {
    // Mis. OrderPolicy::update menolak order yang sudah ditutup — berlaku juga untuk owner
    Gate::define('update-closed-order', fn () => false);

    expect(userWithRole(UserRole::Owner)->can('update-closed-order'))->toBeFalse();
});
