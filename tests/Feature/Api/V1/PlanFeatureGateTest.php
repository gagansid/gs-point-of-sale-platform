<?php

declare(strict_types=1);

use App\Actions\Billing\SavePlan;
use App\Actions\Member\ResolveOrderMember;
use App\Exceptions\BusinessException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Member\Vouchers;
use App\Support\Features;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/*
 * Gerbang fitur paket (ADR 0025 A1): master data `features`, middleware API feature:{key} → 403 FEATURE_NOT_AVAILABLE,
 * pengecekan mesin (member & voucher di checkout), dan `tenant.features` di auth/me.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->forOutlet($this->pos->outlet)->create());
    $this->ownerToken = userToken($this->owner, $this->pos->device);
    TenantContext::forget();
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => []]);
    $this->member = app(SavePlan::class)->handle(null, ['code' => 'member', 'name' => 'Member', 'price_monthly' => '149000', 'price_yearly' => '1490000', 'features' => ['member']]);
});

afterEach(fn () => config(['pos.edition' => 'saas']));

/** Lolos gerbang paket: boleh ditolak aturan lain (mis. program member belum aktif), asal bukan FEATURE_NOT_AVAILABLE. */
function assertPassesFeatureGate(TestResponse $response): void
{
    expect($response->json('error.code'))->not->toBe('FEATURE_NOT_AVAILABLE')
        ->and($response->status())->toBeIn([200, 422]);
}

function gatePlan(Tenant $tenant, ?Plan $plan): void
{
    $tenant->forceFill(['plan_id' => $plan?->id])->save();
    freshAuth();
}

describe('master data features', function () {
    it('berisi 6 fitur ADR 0024 + 4 fitur A2 berurutan', function () {
        expect(array_keys(Feature::options()))->toBe([
            'member', 'self_order', 'stock', 'recipes', 'promotions', 'credit',
            'advanced_reports', 'report_export', 'web_cashier', 'online_payment',
        ]);
    });

    it('fitur yang ditambah langsung di database bisa dipilih di paket; key tak dikenal ditolak', function () {
        Feature::query()->forceCreate(['key' => 'loyalty_plus', 'name' => 'Loyalty Plus', 'sort_order' => 99]);

        $plan = app(SavePlan::class)->handle($this->basic, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => ['loyalty_plus']]);
        expect($plan->features)->toBe(['loyalty_plus']);

        expect(fn () => app(SavePlan::class)->handle($this->basic, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => ['tidak_ada']]))
            ->toThrow(ValidationException::class);
    });
});

describe('middleware API feature:*', function () {
    it('paket tanpa member → endpoint member & voucher & laporan member ditolak', function (string $method, string $uri) {
        gatePlan($this->tenant, $this->basic);
        $uri = str_replace('{id}', (string) Str::uuid(), $uri);

        $response = $this->withToken($this->ownerToken)->json($method, $uri, [], apiHeaders());

        assertApiError($response, 'FEATURE_NOT_AVAILABLE', 403);
        $response->assertJsonPath('error.details.feature', 'member');
    })->with([
        'daftar member' => ['GET', '/api/v1/members'],
        'daftar member baru' => ['POST', '/api/v1/members'],
        'detail member' => ['GET', '/api/v1/members/{id}'],
        'cek voucher' => ['GET', '/api/v1/vouchers/check?code=HEMAT'],
        'laporan member' => ['GET', '/api/v1/reports/members'],
    ]);

    it('paket dengan member tapi tanpa kasbon → endpoint kasbon ditolak dengan fitur credit', function () {
        gatePlan($this->tenant, $this->member);

        $response = $this->withToken($this->ownerToken)->getJson('/api/v1/members/'.Str::uuid().'/receivables', apiHeaders());

        assertApiError($response, 'FEATURE_NOT_AVAILABLE', 403);
        $response->assertJsonPath('error.details.feature', 'credit');
    });

    it('paket tanpa self-order → endpoint pesanan meja ditolak', function () {
        gatePlan($this->tenant, $this->basic);

        $response = $this->withToken($this->pos->token)->getJson('/api/v1/self-orders', apiHeaders());

        assertApiError($response, 'FEATURE_NOT_AVAILABLE', 403);
        $response->assertJsonPath('error.details.feature', 'self_order');
    });

    it('paket dengan fitur → lolos gerbang (tidak FEATURE_NOT_AVAILABLE)', function () {
        gatePlan($this->tenant, $this->member);

        assertPassesFeatureGate($this->withToken($this->ownerToken)->getJson('/api/v1/members', apiHeaders()));
    });

    it('tenant tanpa paket & edisi jual putus → semua fitur', function () {
        gatePlan($this->tenant, null);
        assertPassesFeatureGate($this->withToken($this->ownerToken)->getJson('/api/v1/members', apiHeaders()));

        gatePlan($this->tenant, $this->basic);
        config(['pos.edition' => 'self_hosted']);
        assertPassesFeatureGate($this->withToken($this->ownerToken)->getJson('/api/v1/members', apiHeaders()));
    });

    it('paket tenant lain tidak memengaruhi tenant ini (isolasi tenant)', function () {
        $other = posSetup(openShift: false);
        Tenant::query()->whereKey($other->tenantId)->update(['plan_id' => $this->basic->id]);
        gatePlan($this->tenant, $this->member);

        $otherOwner = TenantContext::run($other->tenantId, fn () => User::factory()->owner()->forOutlet($other->outlet)->create());
        TenantContext::forget();

        assertPassesFeatureGate($this->withToken($this->ownerToken)->getJson('/api/v1/members', apiHeaders()));
        freshAuth();
        assertApiError($this->withToken(userToken($otherOwner, $other->device))->getJson('/api/v1/members', apiHeaders()), 'FEATURE_NOT_AVAILABLE', 403);
    });
});

describe('mesin checkout', function () {
    it('transaksi bermember & voucher ditolak bila paket tanpa member', function () {
        gatePlan($this->tenant, $this->basic);

        TenantContext::run($this->pos->tenantId, function (): void {
            expect(fn () => app(ResolveOrderMember::class)->handle((string) Str::uuid()))
                ->toThrow(BusinessException::class, 'Fitur ini tidak termasuk paket langganan bisnis Anda')
                ->and(fn () => app(Vouchers::class)->resolve('HEMAT', null, null, $this->pos->outlet->id, []))
                ->toThrow(BusinessException::class, 'Fitur ini tidak termasuk paket langganan bisnis Anda')
                // Tanpa member/voucher tidak ada pengecekan
                ->and(app(ResolveOrderMember::class)->handle(null))->toBeNull()
                ->and(app(Vouchers::class)->resolve(null, null, null, $this->pos->outlet->id, []))->toBeNull();
        });
    });
});

describe('auth/me tenant.features', function () {
    it('mengirim fitur paket, atau semua fitur bila tanpa paket', function () {
        gatePlan($this->tenant, $this->member);
        $this->withToken($this->ownerToken)->getJson('/api/v1/auth/me', apiHeaders())
            ->assertOk()->assertJsonPath('data.tenant.features', ['member']);

        gatePlan($this->tenant, null);
        $this->withToken($this->ownerToken)->getJson('/api/v1/auth/me', apiHeaders())
            ->assertOk()->assertJsonPath('data.tenant.features', array_keys(Feature::options()));
    });

    it('key di paket yang sudah dihapus dari master data tidak dikirim', function () {
        $this->member->forceFill(['features' => ['member', 'dihapus']])->save();
        gatePlan($this->tenant, $this->member);

        expect(Features::enabledKeys($this->tenant->refresh()))->toBe(['member']);
    });
});
