<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SetTenantPlan;
use App\Actions\Cashier\RegisterWebDevice;
use App\Actions\Device\SetDeviceRevoked;
use App\Actions\Member\Data\MemberData;
use App\Actions\Member\SaveMember;
use App\Actions\Member\SetMemberActive;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveProduct;
use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SetEmployeeActive;
use App\Enums\BillingCycle;
use App\Enums\ProductKind;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quota;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Quotas;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Kuota paket (ADR 0025 B1): master data `quotas`, batas efektif (batas khusus > paket > tanpa batas), pengecekan
 * saat membuat/mengaktifkan karyawan, perangkat, produk, member; data lama yang lewat batas tetap jalan.
 *
 * posSetup(): 1 outlet aktif, 1 perangkat, 2 karyawan (kasir & supervisor), 2 produk aktif.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->forOutlet($this->pos->outlet)->create());
    TenantContext::forget();
});

afterEach(fn () => config(['pos.edition' => 'saas']));

function quotaLimit(Tenant $tenant, array $limits): void
{
    $tenant->forceFill(['limit_overrides' => $limits])->save();
    freshAuth();
}

/** Jalankan di tenant context; kembalikan kode error bisnis (null = berhasil). */
function quotaError(string $tenantId, Closure $action): ?string
{
    try {
        TenantContext::run($tenantId, $action);
    } catch (BusinessException $e) {
        return $e->errorCode;
    } finally {
        TenantContext::forget();
    }

    return null;
}

function quotaEmployee(string $username): array
{
    return ['name' => ucfirst($username), 'role' => UserRole::Cashier->value, 'username' => $username, 'email' => "{$username}@kopi.test", 'pin' => '481920', 'password' => 'rahasia123'];
}

describe('master data & batas efektif', function () {
    it('berisi 5 kuota berurutan', function () {
        expect(array_keys(Quota::options()))->toBe(['outlets', 'users', 'devices', 'products', 'members']);
    });

    it('batas khusus menimpa paket; tanpa keduanya = tanpa batas; jual putus = tanpa batas', function () {
        $plan = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['users' => 5, 'products' => 100]]);
        $this->tenant->forceFill(['plan_id' => $plan->id, 'limit_overrides' => ['users' => 8]])->save();
        $tenant = $this->tenant->refresh();

        expect($tenant->limit('users'))->toBe(8)
            ->and($tenant->limit('products'))->toBe(100)
            ->and($tenant->limit('members'))->toBeNull();

        config(['pos.edition' => 'self_hosted']);
        expect($tenant->limit('users'))->toBeNull();
    });

    it('menerapkan paket menghapus batas khusus; menghapus paket mempertahankannya', function () {
        $plan = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['outlets' => 2]]);
        quotaLimit($this->tenant, ['outlets' => 1, 'users' => 3]);

        app(SetTenantPlan::class)->handle($this->tenant, null, null);
        expect($this->tenant->refresh()->limit_overrides)->toEqual(['outlets' => 1, 'users' => 3]);

        app(SetTenantPlan::class)->handle($this->tenant, $plan, BillingCycle::Monthly);
        expect($this->tenant->refresh()->limit_overrides)->toBeNull()
            ->and($this->tenant->outletLimit())->toBe(2);
    });

    it('SavePlan menolak kuota tak dikenal & angka < 1; kosong dibuang', function () {
        $plan = app(SavePlan::class)->handle(null, ['code' => 'x', 'name' => 'X', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['users' => '5', 'devices' => '', 'members' => null]]);
        expect($plan->limits)->toBe(['users' => 5]);

        expect(fn () => app(SavePlan::class)->handle($plan, ['code' => 'x', 'name' => 'X', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['kursi' => 3]]))
            ->toThrow(ValidationException::class);
        expect(fn () => app(SavePlan::class)->handle($plan, ['code' => 'x', 'name' => 'X', 'price_monthly' => '1', 'price_yearly' => '1', 'limits' => ['users' => 0]]))
            ->toThrow(ValidationException::class);
    });

});

describe('karyawan', function () {
    it('tambah karyawan ditolak saat penuh dengan details kuota; data lama tetap bisa diubah', function () {
        quotaLimit($this->tenant, ['users' => 3]);

        try {
            TenantContext::run($this->pos->tenantId, fn () => app(SaveEmployee::class)->handle(null, EmployeeData::fromArray(quotaEmployee('dodi')), $this->owner));
            $this->fail('Seharusnya ditolak');
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe('PLAN_LIMIT_REACHED')->and($e->status)->toBe(409)
                ->and($e->details)->toBe(['quota' => 'users', 'limit' => 3, 'used' => 3])
                ->and($e->getMessage())->toContain('karyawan aktif');
        } finally {
            TenantContext::forget();
        }

        expect(User::forTenant($this->pos->tenantId)->count())->toBe(3);
        // Lewat batas setelah downgrade: karyawan lama tetap bisa login & diubah
        quotaLimit($this->tenant, ['users' => 1]);
        expect(quotaError($this->pos->tenantId, fn () => app(SaveEmployee::class)->handle($this->pos->cashier->fresh(), EmployeeData::fromArray(['name' => 'Budi Baru', 'role' => 'cashier', 'username' => 'budi', 'email' => 'budi@kopi.test', 'outlet_ids' => [$this->pos->outlet->id]]), $this->owner)))->toBeNull();
    });

    it('mengaktifkan lagi karyawan ditolak saat penuh; menonaktifkan selalu boleh', function () {
        quotaLimit($this->tenant, ['users' => 3]);
        expect(quotaError($this->pos->tenantId, fn () => app(SetEmployeeActive::class)->handle($this->pos->cashier, false, $this->owner)))->toBeNull();
        TenantContext::run($this->pos->tenantId, fn () => app(SaveEmployee::class)->handle(null, EmployeeData::fromArray(quotaEmployee('dodi')), $this->owner));

        expect(quotaError($this->pos->tenantId, fn () => app(SetEmployeeActive::class)->handle($this->pos->cashier->fresh(), true, $this->owner)))->toBe('PLAN_LIMIT_REACHED')
            ->and($this->pos->cashier->fresh()->is_active)->toBeFalse();
    });

    it('API tambah karyawan → 409 PLAN_LIMIT_REACHED', function () {
        quotaLimit($this->tenant, ['users' => 3]);

        $response = $this->withToken(userToken($this->owner, $this->pos->device))->postJson('/api/v1/users', [...quotaEmployee('dodi'), 'outlet_ids' => [$this->pos->outlet->id]], apiHeaders());

        assertApiError($response, 'PLAN_LIMIT_REACHED', 409);
        $response->assertJsonPath('error.details', ['quota' => 'users', 'limit' => 3, 'used' => 3]);
    });
});

describe('perangkat', function () {
    it('daftar perangkat baru ditolak; daftar ulang perangkat aktif tetap boleh', function () {
        quotaLimit($this->tenant, ['devices' => 1]);
        $token = userToken($this->owner);

        assertApiError($this->withToken($token)->postJson('/api/v1/devices', ['name' => 'Kasir 2', 'device_uid' => 'kasir-2', 'platform' => 'android', 'app_version' => '1.0.0'], apiHeaders()), 'PLAN_LIMIT_REACHED', 409);

        freshAuth();
        $this->withToken($token)->postJson('/api/v1/devices', ['name' => 'Kasir Depan', 'device_uid' => 'kasir-1', 'platform' => 'android', 'app_version' => '1.0.0'], apiHeaders())
            ->assertSuccessful();
        expect(Device::forTenant($this->pos->tenantId)->count())->toBe(1);
    });

    it('mengaktifkan lagi perangkat & kasir web baru ikut dihitung', function () {
        $revoked = Device::factory()->forOutlet($this->pos->outlet)->create(['device_uid' => 'lama', 'revoked_at' => now()]);
        quotaLimit($this->tenant, ['devices' => 1]);

        expect(quotaError($this->pos->tenantId, fn () => app(SetDeviceRevoked::class)->handle($revoked, false)))->toBe('PLAN_LIMIT_REACHED')
            ->and($revoked->fresh()->revoked_at)->not->toBeNull();

        $cashier = $this->pos->cashier;
        $cashier->forceFill(['web_cashier_access' => true])->save();
        $cashier->forgetOutletIds();
        expect(quotaError($this->pos->tenantId, fn () => app(RegisterWebDevice::class)->handle($cashier, $this->pos->outlet->fresh(), (string) Str::uuid())))->toBe('PLAN_LIMIT_REACHED');
    });
});

describe('produk', function () {
    it('produk baru & aktif lagi ditolak; bahan baku dan produk nonaktif tidak dihitung', function () {
        quotaLimit($this->tenant, ['products' => 2]);
        $save = fn (?Product $p, array $data) => quotaError($this->pos->tenantId, fn () => app(SaveProduct::class)->handle($p, ProductData::fromArray($data), $this->pos->outlet));

        expect($save(null, ['name' => 'Teh', 'price' => '10000']))->toBe('PLAN_LIMIT_REACHED')
            ->and($save(null, ['name' => 'Gula', 'price' => '0', 'kind' => ProductKind::Ingredient->value]))->toBeNull()
            ->and($save(null, ['name' => 'Teh Nonaktif', 'price' => '10000', 'is_active' => false]))->toBeNull();

        $inactive = Product::forTenant($this->pos->tenantId)->where('name', 'Teh Nonaktif')->sole();
        expect($save($inactive, ['name' => 'Teh Nonaktif', 'price' => '10000', 'is_active' => true]))->toBe('PLAN_LIMIT_REACHED')
            // Mengubah produk aktif yang sudah ada tetap boleh
            ->and($save($this->pos->coffee->fresh(), ['name' => 'Es Kopi Susu Besar', 'price' => '25000']))->toBeNull();
    });
});

describe('member', function () {
    beforeEach(function () {
        Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-kuota']);
        TenantContext::run($this->pos->tenantId, function (): void {
            app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
            Member::factory()->create(['phone' => '+6281200000001']);
        });
        TenantContext::forget();
        quotaLimit($this->tenant, ['members' => 1]);
    });

    it('daftar member ditolak di dashboard/API; aktif lagi ditolak', function () {
        $data = MemberData::fromArray(['name' => 'Andi', 'phone' => '0812 3333 4444', 'consent' => true]);
        expect(quotaError($this->pos->tenantId, fn () => app(SaveMember::class)->handle(null, $data)))->toBe('PLAN_LIMIT_REACHED');

        $old = Member::forTenant($this->pos->tenantId)->sole();
        TenantContext::run($this->pos->tenantId, fn () => app(SetMemberActive::class)->handle($old, false));
        TenantContext::run($this->pos->tenantId, fn () => app(SaveMember::class)->handle(null, $data));
        TenantContext::forget();

        expect(quotaError($this->pos->tenantId, fn () => app(SetMemberActive::class)->handle($old->fresh(), true)))->toBe('PLAN_LIMIT_REACHED');
    });

    it('daftar mandiri publik: pesan umum tanpa angka kuota', function () {
        $response = $this->post('/m/kopi-kuota/register', ['name' => 'Budi', 'phone' => '0812 3456 7890', 'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123', 'consent' => '1']);

        $response->assertSessionHasErrors(['phone' => 'Pendaftaran member online sedang tidak tersedia. Silakan daftar lewat kasir.']);
        expect(Member::forTenant($this->pos->tenantId)->count())->toBe(1);
    });

    it('portal member 404 bila paket tanpa fitur member', function () {
        $plan = app(SavePlan::class)->handle(null, ['code' => 'nomember', 'name' => 'Tanpa member', 'price_monthly' => '1', 'price_yearly' => '1', 'features' => []]);
        $this->tenant->forceFill(['plan_id' => $plan->id])->save();
        freshAuth();

        $this->get('/m/kopi-kuota/login')->assertNotFound();
    });
});

it('pemakaian tenant lain tidak dihitung (isolasi tenant)', function () {
    $other = posSetup(openShift: false);
    TenantContext::run($other->tenantId, fn () => User::factory()->count(5)->create());
    TenantContext::forget();
    quotaLimit($this->tenant, ['users' => 4]);

    expect(Quotas::used($this->pos->tenantId, Quotas::USERS))->toBe(3)
        ->and(quotaError($this->pos->tenantId, fn () => app(SaveEmployee::class)->handle(null, EmployeeData::fromArray(quotaEmployee('dodi')), $this->owner)))->toBeNull();
});

it('migration B1: max_outlets lama pindah ke limits / limit_overrides', function () {
    $migration = require database_path('migrations/2026_11_02_100000_create_quotas_and_plan_limits.php');
    $migration->down();

    $planId = (string) Str::uuid7();
    DB::table('plans')->insert(['id' => $planId, 'code' => 'old', 'name' => 'Old', 'price_monthly' => 1, 'price_yearly' => 1, 'max_outlets' => 3, 'features' => '[]', 'is_active' => true, 'is_public' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $copied = Tenant::factory()->create();
    $manual = Tenant::factory()->create();
    DB::table('tenants')->where('id', $copied->id)->update(['plan_id' => $planId, 'max_outlets' => 3]);
    DB::table('tenants')->where('id', $manual->id)->update(['plan_id' => null, 'max_outlets' => 2]);

    $migration->up();

    expect(Plan::query()->findOrFail($planId)->limits)->toBe(['outlets' => 3])
        ->and($copied->refresh()->limit_overrides)->toBeNull()
        ->and($copied->outletLimit())->toBe(3)
        ->and($manual->refresh()->limit_overrides)->toBe(['outlets' => 2]);
});
