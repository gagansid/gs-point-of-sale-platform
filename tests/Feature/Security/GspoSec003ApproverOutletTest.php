<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * GSPOS-SEC-003 (docs/security/reports/2026-10-10-audit.md): approver PIN wajib ditugaskan di
 * outlet tempat aksi dilakukan (ADR 0010). Owner (outlet.access_all) tetap boleh.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01']);
        $this->supervisorB = User::factory()->supervisor()->forOutlet($this->outletB)->create();
        $this->owner = User::factory()->owner()->create();
    });

    freshAuth();
    $this->orderId = $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ], apiHeaders())->assertCreated()->json('data.id');
});

function sec003Void(object $test, string $approverId): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->postJson("/api/v1/orders/{$test->orderId}/void", [
        'reason' => 'Salah input', 'approver_user_id' => $approverId, 'approver_pin' => '123456',
    ], apiHeaders());
}

it('supervisor outlet B tidak bisa meng-approve void di outlet A', function () {
    assertApiError(sec003Void($this, $this->supervisorB->id), 'FORBIDDEN', 403);

    expect(Order::allTenants()->findOrFail($this->orderId)->status->value)->toBe('completed');
});

it('regresi: supervisor outlet A dan owner tetap bisa meng-approve', function (string $who) {
    $approver = $who === 'owner' ? $this->owner : $this->pos->supervisor;

    sec003Void($this, $approver->id)->assertOk()->assertJsonPath('data.void_approved_by', $approver->id);
})->with(['supervisor-a', 'owner']);
