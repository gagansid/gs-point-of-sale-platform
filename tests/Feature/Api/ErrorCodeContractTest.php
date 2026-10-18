<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Member\VerifyMemberProof;
use App\Actions\Outlet\CreateOutlet;
use App\Actions\Outlet\SetOutletActive;
use App\Actions\Payment\StartOnlinePayment;
use App\Actions\Payment\TestPaymentGateway;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Kontrak kode error resmi yang sebelumnya kurang teruji (docs/SPEC.md "Standar respons", App\Enums\ErrorCode).
 * Setiap test memeriksa envelope lengkap: success=false, error.code, status HTTP, dan error.details terdokumentasi.
 * Kode yang hanya bisa dicapai lewat Action/Livewire (bukan route API) diuji di level Action: errorCode, status,
 * dan details pada BusinessException (dipetakan 1:1 ke envelope oleh ApiExceptionRenderer).
 *
 * Croissant 24.000 → total 28.000 (service 5%, pajak 11%, pembulatan Rp100). 1 poin = Rp100.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::run($this->pos->tenantId, function (): void {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $this->member = Member::factory()->create(['name' => 'Rina']);
        $this->owner = User::factory()->owner()->create();
        app(AdjustMemberPoints::class)->handle($this->member, 500, 'Saldo awal', $this->owner);
        app(SetMemberPin::class)->handle($this->member, '4821');
        $this->pointsMethod = PaymentMethod::query()->where('category', PaymentCategory::Points)->sole();
    });
});

function eccApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Body checkout croissant: 5.000 dibayar poin + sisa tunai. */
function eccRedeemBody(object $test, array $override = [], int $points = 5000): array
{
    return [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => [
            ['id' => uuid(), 'payment_method_id' => $test->pointsMethod->id, 'amount' => $points],
            ['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 50000],
        ],
        ...$override,
    ];
}

/** Jalankan $call dan kembalikan BusinessException yang dilempar (gagal bila tidak ada). */
function eccCatch(Closure $call): BusinessException
{
    try {
        $call();
    } catch (BusinessException $e) {
        return $e;
    }

    throw new RuntimeException('BusinessException tidak dilempar');
}

describe('MEMBER_PIN_LOCKED', function () {
    it('PIN member salah 5 kali di checkout → 423 dengan error.details.retry_after; PIN benar pun ditolak selama terkunci', function () {
        foreach (range(1, VerifyMemberProof::MAX_PIN_ATTEMPTS - 1) as $i) {
            assertApiError(eccApi($this, 'POST', '/checkout', eccRedeemBody($this, ['member_pin' => '1111'])), 'INVALID_MEMBER_PROOF', 422);
        }

        $locked = eccApi($this, 'POST', '/checkout', eccRedeemBody($this, ['member_pin' => '1111']));
        assertApiError($locked, 'MEMBER_PIN_LOCKED', 423);
        $locked->assertJsonPath('error.details.retry_after', VerifyMemberProof::LOCK_MINUTES * 60);

        $again = eccApi($this, 'POST', '/checkout', eccRedeemBody($this, ['member_pin' => '4821']));
        assertApiError($again, 'MEMBER_PIN_LOCKED', 423);
        expect($again->json('error.details.retry_after'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(VerifyMemberProof::LOCK_MINUTES * 60)
            ->and(Order::allTenants()->count())->toBe(0)
            ->and($this->member->refresh()->points_balance)->toBe(500);
    });
});

describe('INSUFFICIENT_POINTS', function () {
    it('tukar poin melebihi saldo lewat POST /checkout → 422 dengan error.details.balance, tanpa order', function () {
        // Sisa 50 poin (Rp5.000); tukar 60 poin (Rp6.000, masih di bawah 50% tagihan)
        TenantContext::run($this->pos->tenantId, fn () => app(AdjustMemberPoints::class)->handle($this->member, -450, 'Koreksi', $this->owner));

        $response = eccApi($this, 'POST', '/checkout', eccRedeemBody($this, ['member_pin' => '4821'], 6000));

        assertApiError($response, 'INSUFFICIENT_POINTS', 422);
        $response->assertJsonPath('error.details.balance', 50);
        expect(Order::allTenants()->count())->toBe(0)
            ->and($this->member->refresh()->points_balance)->toBe(50);
    });
});

describe('EMAIL_NOT_VERIFIED', function () {
    it('POST /orders/{id}/payments ditolak 403 selama email owner belum diverifikasi; tagihan tetap terbuka', function () {
        $billId = uuid();
        eccApi($this, 'PUT', "/orders/{$billId}", [
            'order_type' => 'dine_in', 'table_label' => 'Meja 1',
            'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        ])->assertSuccessful();

        // Satu-satunya owner aktif belum verifikasi email
        $this->owner->forceFill(['email_verified_at' => null])->save();

        $body = ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]]];
        $response = eccApi($this, 'POST', "/orders/{$billId}/payments", $body);

        assertApiError($response, 'EMAIL_NOT_VERIFIED', 403);
        $response->assertJsonPath('error.details', null);
        expect(Payment::allTenants()->where('order_id', $billId)->count())->toBe(0)
            ->and(Order::allTenants()->findOrFail($billId)->status->value)->toBe('open');

        $this->owner->forceFill(['email_verified_at' => now()])->save();
        eccApi($this, 'POST', "/orders/{$billId}/payments", $body)->assertCreated();
    });
});

describe('PAYMENT_GATEWAY_NOT_CONFIGURED (hanya Action: self-order Livewire & dashboard)', function () {
    it('StartOnlinePayment tanpa setelan Midtrans → 422', function () {
        $e = TenantContext::run($this->pos->tenantId, fn () => eccCatch(fn () => app(StartOnlinePayment::class)->handle(new Order, 'Andi', 'https://x.test/back')));

        expect($e->errorCode)->toBe('PAYMENT_GATEWAY_NOT_CONFIGURED')
            ->and($e->status)->toBe(422)
            ->and($e->status)->toBe(ErrorCode::PaymentGatewayNotConfigured->status())
            ->and($e->details)->toBeNull();
    });

    it('TestPaymentGateway tanpa Server Key → 422 tanpa memanggil Midtrans', function () {
        Http::fake();

        $e = TenantContext::run($this->pos->tenantId, fn () => eccCatch(fn () => app(TestPaymentGateway::class)->handle()));

        expect($e->errorCode)->toBe('PAYMENT_GATEWAY_NOT_CONFIGURED')
            ->and($e->status)->toBe(422)
            ->and($e->getMessage())->toBe('Simpan Server Key dulu sebelum tes koneksi');
        Http::assertNothingSent();
    });
});

describe('PAYMENT_GATEWAY_ERROR (hanya Action/Service)', function () {
    beforeEach(function () {
        TenantContext::run($this->pos->tenantId, fn () => app(UpdatePaymentGateway::class)->handle([
            'enabled' => true, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz',
        ]));
    });

    it('Midtrans 401 saat tes koneksi → 502, verified_at tidak diisi', function () {
        Http::fake(['*' => Http::response(['status_code' => '401'], 401)]);

        $e = TenantContext::run($this->pos->tenantId, fn () => eccCatch(fn () => app(TestPaymentGateway::class)->handle()));

        expect($e->errorCode)->toBe('PAYMENT_GATEWAY_ERROR')
            ->and($e->status)->toBe(502)
            ->and($e->status)->toBe(ErrorCode::PaymentGatewayError->status());
        expect(TenantContext::run($this->pos->tenantId, fn () => PaymentGatewaySetting::current()->verified_at))->toBeNull();
    });

    it('Midtrans 500 saat membuat Snap → 502', function () {
        Http::fake(['*' => Http::response(['error_messages' => ['internal']], 500)]);

        $e = TenantContext::run($this->pos->tenantId, fn () => eccCatch(
            fn () => MidtransClient::for(PaymentGatewaySetting::current())->createSnap(uuid(), 28000, ['first_name' => 'Andi'], 'https://x.test/back'),
        ));

        expect($e->errorCode)->toBe('PAYMENT_GATEWAY_ERROR')
            ->and($e->status)->toBe(502)
            ->and($e->getMessage())->toBe('Midtrans menolak permintaan. Coba lagi atau bayar di kasir');
    });
});

describe('OUTLET_LIMIT_REACHED (tanpa route API; jalur kedua lewat Action)', function () {
    it('mengaktifkan lagi outlet nonaktif melebihi max_outlets → 409 dengan details.max_outlets', function () {
        $tenant = Tenant::query()->findOrFail($this->pos->tenantId);
        $tenant->update(['limit_overrides' => ['outlets' => 1]]);
        $inactive = TenantContext::run($tenant->id, fn () => Outlet::factory()->create(['tenant_id' => $tenant->id, 'code' => 'BDG01', 'is_active' => false]));

        $e = TenantContext::run($tenant->id, fn () => eccCatch(fn () => app(SetOutletActive::class)->handle($inactive, true)));

        expect($e->errorCode)->toBe('OUTLET_LIMIT_REACHED')
            ->and($e->status)->toBe(409)
            ->and($e->details)->toBe(['max_outlets' => 1, 'quota' => 'outlets', 'limit' => 1, 'used' => 1])
            ->and($inactive->refresh()->is_active)->toBeFalse();
    });

    it('CreateOutlet melebihi batas → envelope details sama (max_outlets)', function () {
        Tenant::query()->whereKey($this->pos->tenantId)->update(['limit_overrides' => ['outlets' => 1]]);

        $e = TenantContext::run($this->pos->tenantId, fn () => eccCatch(fn () => app(CreateOutlet::class)->handle('SBY01', 'Surabaya', null, 'Asia/Jakarta')));

        expect($e->errorCode)->toBe('OUTLET_LIMIT_REACHED')->and($e->status)->toBe(409)->and($e->details)->toBe(['max_outlets' => 1, 'quota' => 'outlets', 'limit' => 1, 'used' => 1]);
    });
});

// LAST_OWNER_REQUIRED: hanya satu jalur yang bisa dicapai (PUT /users/{id} menurunkan role owner terakhir,
// sudah diuji di EmployeeTest). Jalur nonaktifkan (SetEmployeeActive) tidak tercapai: user.manage hanya milik
// owner, pelaku tidak bisa menonaktifkan diri sendiri (VALIDATION_ERROR), sehingga pelaku sendiri selalu
// menjadi "owner aktif lain".
