<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Enums\OrderStatus;
use App\Models\Device;
use App\Models\Member;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * Pecah & gabung open bill (ADR 0019, Q95–Q96). Es Kopi Susu 22.000, Croissant 24.000; service 5%, pajak 11%,
 * pembulatan 100.
 */
beforeEach(function () {
    $this->pos = posSetup();
});

function billApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Open bill baru dengan item [product => qty]. */
function openBill(object $test, array $items, array $override = []): string
{
    $id = uuid();
    billApi($test, 'PUT', "/orders/{$id}", [
        'order_type' => 'dine_in', 'table_label' => 'Meja 1',
        'items' => collect($items)->map(fn (int $qty, string $key) => [
            'product_id' => $test->pos->{$key}->id, 'qty' => $qty, 'option_ids' => $key === 'coffee' ? [$test->pos->regular->id] : [],
        ])->values()->all(),
        ...$override,
    ])->assertSuccessful();

    return $id;
}

function billItems(string $orderId): array
{
    return Order::allTenants()->findOrFail($orderId)->items()->withoutGlobalScopes()->get()->mapWithKeys(fn ($i) => [$i->product_name => $i->qty])->all();
}

function itemIdOf(string $orderId, string $productName): string
{
    return (string) Order::allTenants()->findOrFail($orderId)->items()->withoutGlobalScopes()->where('product_name', $productName)->value('id');
}

describe('pecah', function () {
    it('item & qty pindah ke bill baru; kedua bill dihitung ulang; kiriman ulang = hasil sama', function () {
        $source = openBill($this, ['coffee' => 2, 'croissant' => 1]);
        $newId = uuid();
        $body = ['new_order_id' => $newId, 'table_label' => 'Meja 1B', 'items' => [['order_item_id' => itemIdOf($source, 'Es Kopi Susu'), 'qty' => 1]]];

        $response = billApi($this, 'POST', "/orders/{$source}/split", $body)->assertCreated()
            ->assertJsonPath('data.target.id', $newId)
            ->assertJsonPath('data.target.status', 'open')
            ->assertJsonPath('data.target.table_label', 'Meja 1B')
            ->assertJsonPath('data.source.id', $source);

        expect(billItems($source))->toBe(['Es Kopi Susu' => 1, 'Croissant' => 1])
            ->and(billItems($newId))->toBe(['Es Kopi Susu' => 1])
            // 22.000 + 5% + 11% → 25.641 → 25.600
            ->and($response->json('data.target.grand_total'))->toBe('25600.00');

        billApi($this, 'POST', "/orders/{$source}/split", $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        expect(Order::allTenants()->where('status', OrderStatus::Open)->count())->toBe(2);
    });

    it('validasi: qty berlebih, semua item, tanpa item, item bill lain', function (Closure $items) {
        $source = openBill($this, ['coffee' => 1]);

        assertApiError(billApi($this, 'POST', "/orders/{$source}/split", ['new_order_id' => uuid(), 'items' => $items($source)]), 'VALIDATION_ERROR', 422);
        expect(Order::allTenants()->count())->toBe(1);
    })->with([
        'qty berlebih' => [fn (string $s) => [['order_item_id' => itemIdOf($s, 'Es Kopi Susu'), 'qty' => 2]]],
        'semua item' => [fn (string $s) => [['order_item_id' => itemIdOf($s, 'Es Kopi Susu'), 'qty' => 1]]],
        'qty 0' => [fn (string $s) => [['order_item_id' => itemIdOf($s, 'Es Kopi Susu'), 'qty' => 0]]],
        'item lain' => [fn (string $s) => [['order_item_id' => uuid(), 'qty' => 1]]],
    ]);

    it('bill dengan pembayaran tidak bisa dipecah', function () {
        $paid = openBill($this, ['coffee' => 2]);
        billApi($this, 'POST', "/orders/{$paid}/payments", ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 10000]]])->assertSuccessful();
        assertApiError(billApi($this, 'POST', "/orders/{$paid}/split", ['new_order_id' => uuid(), 'items' => [['order_item_id' => itemIdOf($paid, 'Es Kopi Susu'), 'qty' => 1]]]), 'VALIDATION_ERROR', 422);
    });

    it('diskon item dibagi per qty, diskon persen di kedua bill, approval lama ikut tanpa PIN ulang', function () {
        // 44.000: diskon item 3.001,01 + 5% order ≈ 11,5% > batas kasir 10% → PIN supervisor
        $source = openBill($this, [], [
            'items' => [['product_id' => $this->pos->coffee->id, 'qty' => 2, 'option_ids' => [$this->pos->regular->id], 'discount' => '3001.01']],
            'discount' => ['type' => 'percent', 'value' => 5],
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ]);
        $newId = uuid();

        $response = billApi($this, 'POST', "/orders/{$source}/split", ['new_order_id' => $newId, 'items' => [['order_item_id' => itemIdOf($source, 'Es Kopi Susu'), 'qty' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.target.discount', ['type' => 'percent', 'value' => '5.00'])
            ->assertJsonPath('data.source.discount', ['type' => 'percent', 'value' => '5.00'])
            ->assertJsonPath('data.target.approved_by', $this->pos->supervisor->id)
            ->assertJsonPath('data.source.approved_by', $this->pos->supervisor->id);

        expect($response->json('data.target.items.0.discount'))->toBe('1500.51')
            ->and($response->json('data.source.items.0.discount'))->toBe('1500.50');
    });

    it('diskon order nominal tetap di bill asal', function () {
        $source = openBill($this, ['coffee' => 1, 'croissant' => 1], ['discount' => ['type' => 'fixed', 'value' => 4000]]);

        billApi($this, 'POST', "/orders/{$source}/split", ['new_order_id' => uuid(), 'items' => [['order_item_id' => itemIdOf($source, 'Croissant'), 'qty' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.target.discount', null)
            ->assertJsonPath('data.target.order_discount', '0.00')
            ->assertJsonPath('data.source.discount', ['type' => 'fixed', 'value' => '4000.00'])
            ->assertJsonPath('data.source.order_discount', '4000.00');
    });

    it('isolasi: bill tenant lain → 404', function () {
        $foreign = posSetup();
        [$own, $this->pos] = [$this->pos, $foreign];
        $foreignBill = openBill($this, ['coffee' => 2]);
        $this->pos = $own;
        assertApiError(billApi($this, 'POST', "/orders/{$foreignBill}/split", ['new_order_id' => uuid(), 'items' => [['order_item_id' => uuid(), 'qty' => 1]]]), 'NOT_FOUND', 404);
    });
});

describe('gabung', function () {
    it('item bill sumber pindah ke tujuan, sumber berstatus merged; gabung ulang = hasil sama', function () {
        $target = openBill($this, ['coffee' => 1]);
        $source = openBill($this, ['croissant' => 2], ['table_label' => 'Meja 2']);

        billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $source])->assertOk()
            ->assertJsonPath('data.id', $target)
            ->assertJsonPath('data.table_label', 'Meja 1');

        expect(billItems($target))->toBe(['Es Kopi Susu' => 1, 'Croissant' => 2]);
        $merged = Order::allTenants()->findOrFail($source);
        expect($merged->status)->toBe(OrderStatus::Merged)->and($merged->merged_into_id)->toBe($target);

        billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $source])->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        expect(billItems($target))->toBe(['Es Kopi Susu' => 1, 'Croissant' => 2]);
    });

    it('member sumber ikut bila tujuan tanpa member; dua member berbeda ditolak', function () {
        TenantContext::set($this->pos->tenantId);
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        [$a, $b] = [Member::factory()->create(), Member::factory()->create()];
        TenantContext::forget();

        $target = openBill($this, ['coffee' => 1]);
        $source = openBill($this, ['croissant' => 1], ['member_id' => $a->id]);
        billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $source])->assertOk()->assertJsonPath('data.member.id', $a->id);

        $other = openBill($this, ['croissant' => 1], ['member_id' => $b->id]);
        assertApiError(billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $other]), 'VALIDATION_ERROR', 422);
    });

    it('diskon: persen sama tetap persen; berbeda jadi nominal = jumlah potongan; diskon item ikut', function () {
        $target = openBill($this, ['coffee' => 1], ['discount' => ['type' => 'percent', 'value' => 5]]);
        $source = openBill($this, ['croissant' => 1], ['discount' => ['type' => 'percent', 'value' => 5]]);
        billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $source])->assertOk()
            ->assertJsonPath('data.discount', ['type' => 'percent', 'value' => '5.00']);

        // 5% × 22.000 = 1.100 + nominal 1.000 → nominal 2.100
        $target = openBill($this, ['coffee' => 1], ['discount' => ['type' => 'percent', 'value' => 5]]);
        $source = openBill($this, [], ['items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1, 'discount' => '1000']], 'discount' => ['type' => 'fixed', 'value' => 1000]]);
        $merged = billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $source])->assertOk()
            ->assertJsonPath('data.discount', ['type' => 'fixed', 'value' => '2100.00'])
            ->assertJsonPath('data.order_discount', '2100.00');

        expect(collect($merged->json('data.items'))->firstWhere('product_name', 'Croissant')['discount'])->toBe('1000.00');
    });

    it('bill yang sama, sudah selesai, atau dari device outlet lain ditolak', function () {
        $target = openBill($this, ['coffee' => 1]);
        assertApiError(billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $target]), 'VALIDATION_ERROR', 422);
        assertApiError(billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => uuid()]), 'NOT_FOUND', 404);
        assertApiError(billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => 'bukan-uuid']), 'VALIDATION_ERROR', 422);

        $outlet2 = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create());
        $device2 = Device::factory()->forOutlet($outlet2)->create();
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
        assertApiError(billApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $target], userToken($owner, $device2)), 'NOT_FOUND', 404);
    });
});
