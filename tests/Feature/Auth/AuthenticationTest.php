<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant'])->prefix('api/_test')->group(function () {
        Route::get('me', fn (Request $r) => ApiResponse::success([
            'id' => $r->user()?->getKey(),
            'type' => $r->user() !== null ? class_basename($r->user()) : null,
            'tenant_id' => TenantContext::id(),
        ]));
        Route::get('outlets/{outlet}', fn (Outlet $outlet) => ApiResponse::success(['code' => $outlet->code]));
    });
});

describe('token API', function () {
    it('menemukan pemilik token walau tenant belum diketahui, lalu mengisi tenant context', function () {
        $user = User::factory()->create();
        $token = $user->createToken('device-1')->plainTextToken;

        $this->withToken($token)->getJson('/api/_test/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.tenant_id', $user->tenant_id);
    });

    it('device token dikenali sebagai device (ADR 0002)', function () {
        $device = Device::factory()->create();
        $token = $device->createToken('device', ['device'])->plainTextToken;

        $this->withToken($token)->getJson('/api/_test/me')
            ->assertOk()
            ->assertJsonPath('data.type', 'Device')
            ->assertJsonPath('data.tenant_id', $device->tenant_id);
    });

    it('token kedaluwarsa ditolak', function () {
        $token = User::factory()->create()->createToken('lama', expiresAt: now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/_test/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('user tenant A tidak bisa membuka outlet tenant B', function () {
        $user = User::factory()->owner()->create();
        $foreign = Outlet::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson("/api/_test/outlets/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    });
});

describe('login sesi (panel /dashboard)', function () {
    it('user aktif bisa login tanpa tenant context', function () {
        $user = User::factory()->owner()->create(['email' => 'owner@contoh.test']);

        expect(Auth::guard('web')->attempt(['email' => 'owner@contoh.test', 'password' => 'password']))->toBeTrue()
            ->and(Auth::guard('web')->id())->toBe($user->id);
    });

    it('user nonaktif tidak bisa login dan sesinya tidak dipulihkan', function () {
        $user = User::factory()->owner()->inactive()->create(['email' => 'mantan@contoh.test']);

        expect(Auth::guard('web')->attempt(['email' => 'mantan@contoh.test', 'password' => 'password']))->toBeFalse()
            ->and(Auth::guard('web')->getProvider()->retrieveById($user->id))->toBeNull();
    });
});

describe('guard admin', function () {
    it('terpisah dari user tenant', function () {
        Admin::factory()->create(['email' => 'admin@contoh.test']);
        User::factory()->owner()->create(['email' => 'owner@contoh.test']);

        expect(Auth::guard('admin')->attempt(['email' => 'admin@contoh.test', 'password' => 'password']))->toBeTrue()
            ->and(Auth::guard('admin')->attempt(['email' => 'owner@contoh.test', 'password' => 'password']))->toBeFalse()
            ->and(Auth::guard('web')->attempt(['email' => 'admin@contoh.test', 'password' => 'password']))->toBeFalse();
    });
});
