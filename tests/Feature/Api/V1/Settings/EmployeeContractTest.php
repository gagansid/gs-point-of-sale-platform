<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\User;
use App\Support\TenantContext;

/*
 * Kontrak karyawan (fase 2): GET /users/{id} dan POST /users/{id}/unlock-pin memerlukan user.manage
 * (hanya owner, UserRole::permissions()); user tenant lain tersembunyi global scope (404).
 * Ditambah logout ganda dengan token yang sama.
 */
beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenantId]);
    $this->token = userToken($this->owner);
    $this->cashier = TenantContext::run($this->tenantId, fn () => User::factory()->cashier()->forOutlet($this->outlet)->create(['pin' => '481920']));
    TenantContext::forget();
});

/** Mengunci PIN karyawan seperti setelah 5 kali salah. */
function employeeContractLockPin(User $user): void
{
    $user->forceFill(['pin_failed_attempts' => 5, 'pin_locked_until' => now()->addMinutes(10)])->save();
}

describe('GET /users/{id}', function () {
    it('menolak role tanpa permission user.manage', function (string $role) {
        $actor = TenantContext::run($this->tenantId, fn () => User::factory()->{$role}()->forOutlet($this->outlet)->create());
        TenantContext::forget();

        assertApiError($this->withToken(userToken($actor))->getJson("/api/v1/users/{$this->cashier->id}", apiHeaders()), 'FORBIDDEN', 403);
    })->with(['manager', 'supervisor', 'cashier']);

    it('karyawan tenant lain → 404', function () {
        $foreign = User::factory()->cashier()->create();

        assertApiError($this->withToken($this->token)->getJson("/api/v1/users/{$foreign->id}", apiHeaders()), 'NOT_FOUND', 404);
    });
});

describe('POST /users/{id}/unlock-pin', function () {
    it('menolak role tanpa permission user.manage dan PIN tetap terkunci', function (string $role) {
        employeeContractLockPin($this->cashier);
        $actor = TenantContext::run($this->tenantId, fn () => User::factory()->{$role}()->forOutlet($this->outlet)->create());
        TenantContext::forget();

        assertApiError(
            $this->withToken(userToken($actor))->postJson("/api/v1/users/{$this->cashier->id}/unlock-pin", [], apiHeaders()),
            'FORBIDDEN',
            403,
        );

        expect($this->cashier->refresh()->pin_locked_until)->not->toBeNull();
    })->with(['manager', 'supervisor']);

    it('karyawan tenant lain → 404 dan PIN-nya tetap terkunci', function () {
        $foreign = User::factory()->cashier()->create();
        employeeContractLockPin($foreign);

        assertApiError(
            $this->withToken($this->token)->postJson("/api/v1/users/{$foreign->id}/unlock-pin", [], apiHeaders()),
            'NOT_FOUND',
            404,
        );

        expect(User::allTenants()->find($foreign->id)?->pin_locked_until)->not->toBeNull();
    });

    it('membuka kunci dua kali tetap 200 dan PIN tetap terbuka', function () {
        employeeContractLockPin($this->cashier);

        $this->withToken($this->token)->postJson("/api/v1/users/{$this->cashier->id}/unlock-pin", [], apiHeaders())
            ->assertOk()->assertJsonPath('data.pin_locked_until', null);

        freshAuth();
        $this->withToken($this->token)->postJson("/api/v1/users/{$this->cashier->id}/unlock-pin", [], apiHeaders())
            ->assertOk()
            ->assertJsonPath('message', 'Kunci PIN dibuka')
            ->assertJsonPath('data.pin_locked_until', null);

        $fresh = $this->cashier->refresh();
        expect($fresh->pin_locked_until)->toBeNull()
            ->and($fresh->pin_failed_attempts)->toBe(0);
    });
});

describe('POST /auth/logout', function () {
    it('logout kedua dengan token yang sama → 401 UNAUTHENTICATED', function () {
        $this->withToken($this->token)->postJson('/api/v1/auth/logout', [], apiHeaders())->assertOk();

        freshAuth();
        assertApiError($this->withToken($this->token)->postJson('/api/v1/auth/logout', [], apiHeaders()), 'UNAUTHENTICATED', 401);
    });
});
