<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenantId]);
    $this->token = userToken($this->owner);
});

function employeeBody(array $override = []): array
{
    return ['name' => 'Budi', 'role' => 'cashier', 'pin' => '481920', 'username' => 'Budi', 'password' => 'rahasia123', ...$override];
}

describe('tambah', function () {
    it('kasir: nama + PIN (tablet) + username & kata sandi (kasir web), tanpa email, terikat outlet', function () {
        $id = $this->withToken($this->token)->postJson('/api/v1/users', employeeBody(), apiHeaders())
            ->assertCreated()
            ->assertJsonPath('message', 'Karyawan berhasil ditambahkan')
            ->assertJsonPath('data.role', 'cashier')
            ->assertJsonPath('data.has_pin', true)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.username', 'budi')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.outlet_id', $this->outlet->id)
            ->assertJsonMissingPath('data.pin')
            ->json('data.id');

        $user = User::allTenants()->find($id);
        expect(Hash::check('481920', (string) $user?->pin))->toBeTrue()
            ->and($user?->tenant_id)->toBe($this->tenantId);
    });

    it('manager wajib email + kata sandi; email disimpan huruf kecil', function () {
        $this->withToken($this->token)->postJson('/api/v1/users', employeeBody([
            'name' => 'Sari', 'role' => 'manager', 'pin' => null, 'username' => null, 'email' => 'SARI@Kopi.test', 'password' => 'rahasia123',
        ]), apiHeaders())->assertCreated()->assertJsonPath('data.email', 'sari@kopi.test');
    });

    it('validasi', function (array $override, string $field) {
        User::factory()->create(['email' => 'pakai@kopi.test']);
        TenantContext::run($this->tenantId, fn () => User::factory()->cashier()->forOutlet($this->outlet)->create(['username' => 'sari']));

        $response = $this->withToken($this->token)->postJson('/api/v1/users', employeeBody($override), apiHeaders());

        assertApiError($response, 'VALIDATION_ERROR', 422);
        expect($response->json('error.details'))->toHaveKey($field);
    })->with([
        'PIN berurutan' => [['pin' => '123456'], 'pin'],
        'PIN angka sama' => [['pin' => '000000'], 'pin'],
        'kasir tanpa PIN' => [['pin' => null], 'pin'],
        'kasir tanpa username' => [['username' => null], 'username'],
        'kasir tanpa kata sandi' => [['password' => null], 'password'],
        'username tidak valid' => [['username' => 'budi santoso'], 'username'],
        'username dipakai di bisnis sama' => [['username' => 'SARI'], 'username'],
        'manager tanpa email' => [['role' => 'manager'], 'email'],
        'manager tanpa kata sandi' => [['role' => 'manager', 'email' => 'm@kopi.test', 'password' => null], 'password'],
        'email terpakai' => [['role' => 'manager', 'email' => 'PAKAI@kopi.test', 'password' => 'rahasia123'], 'email'],
        'role tidak dikenal' => [['role' => 'boss'], 'role'],
    ]);

    it('request ganda dengan id sama tetap satu karyawan', function () {
        $body = employeeBody(['id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6c']);

        $this->withToken($this->token)->postJson('/api/v1/users', $body, apiHeaders())->assertCreated();
        freshAuth();
        $this->withToken($this->token)->postJson('/api/v1/users', $body, apiHeaders())
            ->assertOk()->assertJsonPath('meta.idempotent_replay', true);

        expect(User::allTenants()->where('name', 'Budi')->count())->toBe(1);
    });
});

describe('ubah & nonaktifkan', function () {
    beforeEach(function () {
        $this->cashier = TenantContext::run($this->tenantId, fn () => User::factory()->cashier()->forOutlet($this->outlet)->create([
            'pin' => '481920', 'username' => 'budi', 'password' => 'rahasia123',
        ]));
    });

    it('ganti PIN memutus sesi lama & membuka kunci PIN', function () {
        $this->cashier->forceFill(['pin_failed_attempts' => 5, 'pin_locked_until' => now()->addMinutes(10)])->save();
        $this->cashier->createToken('kasir');

        $this->withToken($this->token)->putJson("/api/v1/users/{$this->cashier->id}", ['pin' => '902817'], apiHeaders())
            ->assertOk()->assertJsonPath('data.name', $this->cashier->name);

        $fresh = $this->cashier->refresh();
        expect(Hash::check('902817', (string) $fresh->pin))->toBeTrue()
            ->and($fresh->pin_locked_until)->toBeNull()
            ->and($fresh->tokens()->count())->toBe(0);
    });

    it('nonaktifkan karyawan memutus sesi; field lain tetap', function () {
        $this->cashier->createToken('kasir');

        $this->withToken($this->token)->putJson("/api/v1/users/{$this->cashier->id}", ['is_active' => false], apiHeaders())
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.has_pin', true);

        expect($this->cashier->refresh()->tokens()->count())->toBe(0);
    });

    it('owner terakhir tidak bisa diturunkan atau dinonaktifkan; diri sendiri tidak bisa dinonaktifkan', function () {
        $other = TenantContext::run($this->tenantId, fn () => User::factory()->owner()->create());
        $otherToken = userToken($other);

        // Sendiri
        assertApiError($this->withToken($this->token)->putJson("/api/v1/users/{$this->owner->id}", ['is_active' => false], apiHeaders()), 'VALIDATION_ERROR', 422);

        // Owner kedua menonaktifkan owner pertama: boleh (masih ada owner lain)
        freshAuth();
        $this->withToken($otherToken)->putJson("/api/v1/users/{$this->owner->id}", ['is_active' => false], apiHeaders())->assertOk();

        // Owner terakhir (other) diturunkan oleh dirinya sendiri → ditolak
        freshAuth();
        assertApiError(
            $this->withToken($otherToken)->putJson("/api/v1/users/{$other->id}", ['role' => 'manager', 'password' => 'rahasia123'], apiHeaders()),
            'LAST_OWNER_REQUIRED',
            409,
        );
        expect($other->refresh()->role)->toBe(UserRole::Owner);
    });

    it('buka kunci PIN', function () {
        $this->cashier->forceFill(['pin_failed_attempts' => 5, 'pin_locked_until' => now()->addMinutes(10)])->save();

        $this->withToken($this->token)->postJson("/api/v1/users/{$this->cashier->id}/unlock-pin", [], apiHeaders())
            ->assertOk()->assertJsonPath('data.pin_locked_until', null);
    });
});

it('daftar karyawan dengan filter role & pencarian', function () {
    TenantContext::run($this->tenantId, function () {
        User::factory()->cashier()->forOutlet($this->outlet)->create(['name' => 'Budi Kasir']);
        User::factory()->supervisor()->forOutlet($this->outlet)->create(['name' => 'Sari']);
    });

    $this->withToken($this->token)->getJson('/api/v1/users?role=cashier&search=budi', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Budi Kasir'])
        ->assertJsonMissingPath('data.0.pin');
});

it('manager, supervisor, kasir tidak boleh mengelola karyawan', function (string $role) {
    $user = TenantContext::run($this->tenantId, fn () => User::factory()->{$role}()->forOutlet($this->outlet)->create());
    $token = userToken($user);

    assertApiError($this->withToken($token)->getJson('/api/v1/users', apiHeaders()), 'FORBIDDEN', 403);
    freshAuth();
    assertApiError($this->withToken($token)->postJson('/api/v1/users', employeeBody(), apiHeaders()), 'FORBIDDEN', 403);
})->with(['manager', 'supervisor', 'cashier']);

it('isolasi tenant: karyawan tenant lain tidak terlihat & tidak bisa diubah', function () {
    $foreign = User::factory()->cashier()->create(['name' => 'Orang Lain']);

    $this->withToken($this->token)->getJson('/api/v1/users', apiHeaders())->assertJsonMissing(['name' => 'Orang Lain']);
    freshAuth();
    assertApiError($this->withToken($this->token)->putJson("/api/v1/users/{$foreign->id}", ['pin' => '902817'], apiHeaders()), 'NOT_FOUND', 404);
});

it('tenant hanya-baca: daftar boleh, tambah ditolak', function () {
    Tenant::query()->whereKey($this->tenantId)->update(['subscription_ends_at' => now()->subDay()]);

    $this->withToken($this->token)->getJson('/api/v1/users', apiHeaders())->assertOk();
    freshAuth();
    assertApiError($this->withToken($this->token)->postJson('/api/v1/users', employeeBody(), apiHeaders()), 'SUBSCRIPTION_EXPIRED', 403);
});

it('username sama boleh di bisnis lain', function () {
    TenantContext::run(Outlet::factory()->create()->tenant_id, fn () => User::factory()->cashier()->create(['username' => 'budi']));

    $this->withToken($this->token)->postJson('/api/v1/users', employeeBody(), apiHeaders())->assertCreated();
});
