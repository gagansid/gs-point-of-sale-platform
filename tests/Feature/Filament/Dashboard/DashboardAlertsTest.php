<?php

declare(strict_types=1);

use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\ReceivableEntryType;
use App\Filament\Dashboard\Pages\Home;
use App\Models\Member;
use App\Models\Product;
use App\Models\User;
use App\Services\Member\ReceivableLedger;
use App\Support\CurrentOutlet;
use App\Support\DashboardAlerts;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/** Lonceng peringatan topbar /dashboard: dihitung langsung, per izin & outlet, tanpa data tenant lain. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> */
function alertKeys(User $user): array
{
    return array_column(DashboardAlerts::for($user), 'key');
}

it('tanpa masalah: tidak ada peringatan; lonceng tetap tampil dengan "Semua aman"', function () {
    // Croissant stok 10 tanpa batas minimum; shift baru dibuka
    expect(DashboardAlerts::for($this->owner))->toBe([]);

    $this->get(Home::getUrl())->assertOk()->assertSee('gs-alerts-trigger', false)->assertSee('Semua aman');
});

it('restok: produk habis & menipis di outlet aktif', function () {
    Product::factory()->tracked(0)->create(['name' => 'Bagel']);
    stockOf($this->pos->croissant, $this->pos->outlet->id)->forceFill(['stock_qty' => 2, 'min_stock' => 5])->save();

    $alert = collect(DashboardAlerts::for($this->owner))->firstWhere('key', 'stock');

    expect($alert)->not->toBeNull()
        ->and($alert['title'])->toBe('2 produk perlu restok')
        ->and($alert['description'])->toBe('1 habis · 1 menipis')
        ->and($alert['color'])->toBe('danger')
        // Tautan membuka tab yang tepat (HasCardTabs ?tab=)
        ->and($alert['url'])->toEndWith('?tab=sold_out');

    $this->get(Home::getUrl())->assertOk()->assertSee('2 produk perlu restok')->assertSee('gs-alerts-badge', false);
});

it('pesanan meja menunggu, shift lupa ditutup, kasbon lama', function () {
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 1']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
    app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($table->qr_token), [
        'id' => uuid(), 'customer_name' => 'Andi',
        'items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id], 'notes' => null]],
    ], null, '127.0.0.1');

    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateCreditProgram::class)->handle(true, '100000');
    $member = Member::factory()->create();
    app(ReceivableLedger::class)->record($member, ReceivableEntryType::Charge, '50000.00');

    // 40 hari kemudian: shift dari beforeEach masih terbuka (> 24 jam) dan kasbon jadi lama (≥ 30 hari)
    Carbon::setTestNow(now()->addDays(40));

    expect(alertKeys($this->owner))->toContain('self_orders', 'shifts', 'receivables');
});

it('permission & isolasi: kasir tanpa peringatan; data tenant lain tidak dihitung', function () {
    expect(DashboardAlerts::for($this->pos->cashier))->toBe([]);

    $other = posSetup();
    TenantContext::run($other->tenantId, fn () => Product::factory()->tracked(0)->create(['name' => 'Habis Tetangga']));
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    expect(alertKeys($this->owner))->not->toContain('stock');
});
