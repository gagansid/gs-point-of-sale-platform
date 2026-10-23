<?php

declare(strict_types=1);

use App\Actions\Fulfillment\AdvanceOrderFulfillment;
use App\Actions\SelfOrder\CheckoutSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Actions\User\UpdateRolePermissions;
use App\Enums\FulfillmentStatus;
use App\Enums\UserRole;
use App\Filament\Dashboard\Resources\FulfillmentQueue\FulfillmentQueueResource;
use App\Filament\Dashboard\Resources\FulfillmentQueue\Pages\ListFulfillmentQueue;
use App\Filament\Dashboard\Resources\FulfillmentQueue\Widgets\FulfillmentStatsWidget;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Dashboard → Transaksi → Antrean pesanan (ADR 0027 K8, SPEC Q137 T5b). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 05:00:00'); // 12.00 WIB
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 7', 'area' => 'Teras']);

    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

/** Pesanan meja lunas (tunai lewat API kasir) → Antre. */
function dashboardPaidOrder(object $test, string $name): Order
{
    $order = app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($test->table->qr_token), [
        'id' => uuid(), 'customer_name' => $name, 'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 2]],
    ], null, '10.7.'.random_int(0, 255).'.'.random_int(0, 255))['order'];

    freshAuth();
    $test->withToken($test->pos->token)->postJson("/api/v1/orders/{$order->id}/payments", [
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 56000]],
    ], apiHeaders())->assertCreated();
    // Header token API tidak boleh terbawa ke request dashboard berikutnya
    $test->flushHeaders();
    freshAuth();
    TenantContext::set($test->pos->tenantId);
    $test->actingAs($test->owner);
    CurrentOutlet::set($test->owner);

    return $order->refresh();
}

it('statistik hari ini, tab berbadge, kolom tabel & langkah dari tabel', function () {
    $andi = dashboardPaidOrder($this, 'Andi');
    $budi = dashboardPaidOrder($this, 'Budi');
    $citra = dashboardPaidOrder($this, 'Citra');
    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $budi, FulfillmentStatus::Preparing);
    Carbon::setTestNow('2026-10-08 05:20:00');
    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $citra, FulfillmentStatus::Served);
    Carbon::setTestNow('2026-10-08 05:40:00');

    Livewire::test(FulfillmentStatsWidget::class)
        ->assertSeeInOrder(['Antre', '1', 'Disiapkan', '1', 'Siap diantar', '0', 'Diantar', '1'])
        ->assertSee('20 mnt') // Citra: lunas 12.00 → diantar 12.20
        ->assertSeeInOrder(['Terlambat', '2']); // Andi & Budi belum diantar > 30 menit

    $page = Livewire::test(ListFulfillmentQueue::class)
        ->assertCanSeeTableRecords([$andi, $budi, $citra])
        ->assertSee('Meja 7')->assertSee('Teras')->assertSee('Andi')->assertSee($andi->order_number)
        ->assertSee('40 mnt')->assertSee('Buka layar antrean')
        ->set('activeTab', 'queued')->assertCanSeeTableRecords([$andi])->assertCanNotSeeTableRecords([$budi, $citra])
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('advance')->table($andi))
        ->assertNotified('Meja 7 · Disiapkan');

    expect($andi->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Preparing)
        ->and($andi->fulfillment_updated_by)->toBe($this->owner->id);

    $page->assertActionHidden(TestAction::make('advance')->table($citra));
});

it('perubahan dari /cashier/queue tampil setelah polling; tenant & outlet lain tidak tampil', function () {
    $andi = dashboardPaidOrder($this, 'Andi');
    $page = Livewire::test(ListFulfillmentQueue::class)->assertSee('Antre');

    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $andi, FulfillmentStatus::Ready);
    $page->call('$refresh')->assertSee('Siap diantar');

    $other = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    expect(FulfillmentQueueResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([$this->pos->tenantId]);
});

it('akses: bawaan manager punya; owner mencabut order.fulfill → menu hilang & 403', function () {
    $this->get(FulfillmentQueueResource::getUrl())->assertOk()->assertSee('Antrean pesanan')->assertSee('Rata-rata lunas → diantar', false);

    $manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
    $this->actingAs($manager);
    expect(FulfillmentQueueResource::canViewAny())->toBeTrue();

    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Manager, array_values(array_diff(UserRole::Manager->grantedPermissions(), ['order.fulfill'])));
    $this->actingAs($manager->fresh());
    expect(FulfillmentQueueResource::canViewAny())->toBeFalse();
    $this->get(FulfillmentQueueResource::getUrl())->assertForbidden();
});
