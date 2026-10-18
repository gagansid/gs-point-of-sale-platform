<?php

declare(strict_types=1);

use App\Actions\Billing\SaveAddon;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SetTenantAddons;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\Addons\Pages\ManageAddons;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Addon;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Support\Features;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Add-on langganan (ADR 0025 C1): CRUD admin, Atur add-on per bisnis tanpa tagihan, fitur paket ∪ add-on, batas
 * kuota paket + add-on.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'limits' => ['outlets' => 1], 'features' => ['member']]);
    $this->tenant->forceFill(['plan_id' => $this->basic->id])->save();
    $this->selfOrder = app(SaveAddon::class)->handle(null, ['code' => 'self-order', 'name' => 'Self-order QR', 'type' => 'feature', 'feature_key' => 'self_order', 'price_monthly' => '50000', 'price_yearly' => '500000']);
    $this->extraOutlet = app(SaveAddon::class)->handle(null, ['code' => 'extra-outlet', 'name' => 'Outlet tambahan', 'type' => 'quota', 'quota_key' => 'outlets', 'quota_amount' => 1, 'price_monthly' => '99000', 'price_yearly' => '990000']);
    freshAuth();
});

afterEach(fn () => config(['pos.edition' => 'saas']));

describe('SaveAddon', function () {
    it('menyimpan add-on fitur & kuota; isian jenis lain dikosongkan', function () {
        expect($this->selfOrder->type)->toBe('feature')->and($this->selfOrder->quota_key)->toBeNull()
            ->and($this->extraOutlet->quota_amount)->toBe(1)->and($this->extraOutlet->feature_key)->toBeNull()
            ->and($this->extraOutlet->benefit())->toBe('+1 Outlet aktif per unit')
            ->and($this->selfOrder->benefit())->toBe('Fitur: Self-order QR meja');
    });

    it('memvalidasi fitur/kuota dari master data & tambahan per unit', function (array $data, string $field) {
        expect(fn () => app(SaveAddon::class)->handle(null, ['code' => 'x', 'name' => 'X', 'price_monthly' => '1', 'price_yearly' => '1', ...$data]))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
    })->with([
        'fitur tak dikenal' => [['type' => 'feature', 'feature_key' => 'terbang'], 'feature_key'],
        'kuota tanpa jumlah' => [['type' => 'quota', 'quota_key' => 'users'], 'quota_amount'],
        'kuota tak dikenal' => [['type' => 'quota', 'quota_key' => 'kursi', 'quota_amount' => 1], 'quota_key'],
        'jenis salah' => [['type' => 'bonus'], 'type'],
    ]);

    it('add-on yang dipakai bisnis: harga boleh diubah, manfaat tidak', function () {
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 1]);
        $data = ['code' => 'extra-outlet', 'name' => 'Outlet tambahan', 'type' => 'quota', 'quota_key' => 'outlets', 'quota_amount' => 1, 'price_monthly' => '89000', 'price_yearly' => '890000'];

        expect(app(SaveAddon::class)->handle($this->extraOutlet, $data)->price_monthly)->toBe('89000.00');
        expect(fn () => app(SaveAddon::class)->handle($this->extraOutlet, [...$data, 'quota_amount' => 2]))->toThrow(ValidationException::class);
    });

    it('admin membuat add-on dari halaman Add-on', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

        Livewire::test(ManageAddons::class)
            ->assertCanSeeTableRecords([$this->selfOrder, $this->extraOutlet])
            ->callAction('create', ['name' => 'Karyawan +5', 'code' => 'extra-users', 'type' => 'quota', 'quota_key' => 'users', 'quota_amount' => 5, 'price_monthly' => '25000', 'price_yearly' => '250000', 'is_active' => true, 'is_public' => true])
            ->assertHasNoActionErrors();

        expect(Addon::query()->where('code', 'extra-users')->sole()->quota_amount)->toBe(5);
    });
});

describe('SetTenantAddons & efeknya', function () {
    it('add-on fitur menambah fitur paket; dilepas = hilang lagi (API ikut)', function () {
        $owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->forOutlet($this->pos->outlet)->create());
        TenantContext::forget();
        expect(Features::enabled('self_order', $this->tenant))->toBeFalse();

        app(SetTenantAddons::class)->handle($this->tenant, [$this->selfOrder->id => true]);
        expect(Features::enabled('self_order', $this->tenant->fresh()))->toBeTrue()
            ->and(Features::enabledKeys($this->tenant->fresh()))->toBe(['member', 'self_order']);
        freshAuth();
        $response = $this->withToken($this->pos->token)->getJson('/api/v1/self-orders', apiHeaders());
        expect($response->json('error.code'))->not->toBe('FEATURE_NOT_AVAILABLE');

        app(SetTenantAddons::class)->handle($this->tenant, []);
        freshAuth();
        expect(TenantAddon::forTenant($this->tenant->id)->count())->toBe(0);
        assertApiError($this->withToken($this->pos->token)->getJson('/api/v1/self-orders', apiHeaders()), 'FEATURE_NOT_AVAILABLE', 403);
    });

    it('add-on kuota menambah batas paket per unit; paket tanpa batas tetap tanpa batas', function () {
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 2]);
        expect($this->tenant->fresh()->outletLimit())->toBe(3);

        $this->basic->forceFill(['limits' => []])->save();
        expect($this->tenant->fresh()->outletLimit())->toBeNull();
    });

    it('menolak: tanpa paket, fitur sudah di paket, fitur > 1 unit, add-on nonaktif baru, jual putus', function (Closure $arrange, Closure $quantities) {
        $arrange($this);

        expect(fn () => app(SetTenantAddons::class)->handle($this->tenant->fresh(), $quantities($this)))->toThrow(BusinessException::class);
        expect(TenantAddon::forTenant($this->tenant->id)->count())->toBe(0);
    })->with([
        'tanpa paket' => [fn ($t) => $t->tenant->forceFill(['plan_id' => null])->save(), fn ($t) => [$t->extraOutlet->id => 1]],
        'fitur sudah di paket' => [fn ($t) => $t->selfOrder->forceFill(['feature_key' => 'member'])->save(), fn ($t) => [$t->selfOrder->id => 1]],
        'fitur > 1 unit' => [fn () => null, fn ($t) => [$t->selfOrder->id => 2]],
        'nonaktif baru' => [fn ($t) => $t->extraOutlet->forceFill(['is_active' => false])->save(), fn ($t) => [$t->extraOutlet->id => 1]],
        'jual putus' => [fn () => config(['pos.edition' => 'self_hosted']), fn ($t) => [$t->extraOutlet->id => 1]],
    ]);

    it('disimpan dua kali tetap satu baris per add-on', function () {
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 1]);
        app(SetTenantAddons::class)->handle($this->tenant, [$this->extraOutlet->id => 3]);

        expect(TenantAddon::forTenant($this->tenant->id)->sole()->quantity)->toBe(3);
    });

    it('add-on bisnis lain tidak berpengaruh (isolasi tenant)', function () {
        $other = posSetup(openShift: false);
        $otherTenant = Tenant::query()->findOrFail($other->tenantId);
        $otherTenant->forceFill(['plan_id' => $this->basic->id])->save();
        app(SetTenantAddons::class)->handle($otherTenant, [$this->selfOrder->id => 1, $this->extraOutlet->id => 5]);
        freshAuth();

        expect(Features::enabled('self_order', $this->tenant->fresh()))->toBeFalse()
            ->and($this->tenant->fresh()->outletLimit())->toBe(1)
            ->and($otherTenant->fresh()->outletLimit())->toBe(6);
    });
});

it('admin: Atur add-on di detail bisnis & tampil di section Langganan', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

    Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
        ->callAction('addons', ['addons' => [$this->selfOrder->id => true, $this->extraOutlet->id => 2]])
        ->assertHasNoActionErrors()
        ->assertNotified('Add-on disimpan');

    expect(TenantAddon::forTenant($this->tenant->id)->count())->toBe(2);

    Livewire::test(ViewTenant::class, ['record' => $this->tenant->id])
        ->assertSee('Self-order QR')
        ->assertSee('Outlet tambahan ×2');
});
