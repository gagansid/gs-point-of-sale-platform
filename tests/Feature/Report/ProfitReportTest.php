<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\ReportTab;
use App\Filament\Dashboard\Widgets\Reports\ProfitStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ProfitTableWidget;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * R1 Laba kotor (Q53). posSetup: pajak 11% eksklusif, service 5%, pembulatan 100.
 * Croissant 24.000 (modal 9.000 dari factory), kopi 22.000 (modal diset 8.000), opsi Large +5.000 tanpa modal.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    Carbon::setTestNow('2026-10-08 03:00:00');
    TenantContext::run($this->pos->tenantId, function (): void {
        $this->pos->coffee->update(['cost_price' => '8000.00']);
        $this->pos->croissant->update(['cost_price' => '9000.00']);
    });
});

afterEach(fn () => Carbon::setTestNow());

function profitSale(stdClass $pos, array $items, array $extra = []): void
{
    TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 500000]],
        ...$extra,
    ])));
}

function profitOf(stdClass $pos): array
{
    TenantContext::set($pos->tenantId);
    CurrentOutlet::set(null, $pos->outlet->id);

    return app(ReportService::class)->profit(Carbon::parse('2026-10-08 00:00')->toImmutable(), Carbon::parse('2026-10-08 23:59')->toImmutable());
}

it('HPP dari snapshot, penjualan bersih = net_sales ringkasan (diskon order dialokasikan, tanpa pajak/service)', function () {
    profitSale($this->pos, [['product_id' => $this->pos->croissant->id, 'qty' => 2], ['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]]],
        ['discount' => ['type' => 'fixed', 'value' => '7000']]);

    $profit = profitOf($this->pos);
    $summary = app(ReportService::class)->summary(Carbon::parse('2026-10-08 00:00')->toImmutable(), Carbon::parse('2026-10-08 23:59')->toImmutable());
    $rows = collect($profit['products'])->keyBy('product_name');

    // Subtotal 70.000, diskon 7.000 → faktor 0,9: croissant 48.000 → 43.200; kopi 22.000 → 19.800
    expect($rows['Croissant']['net_sales'])->toBe('43200.00')
        ->and($rows['Croissant']['cogs'])->toBe('18000.00')
        ->and($rows['Croissant']['gross_profit'])->toBe('25200.00')
        ->and($rows['Croissant']['margin'])->toBe('58.3')
        ->and($rows['Es Kopi Susu']['cogs'])->toBe('8000.00')
        ->and($profit['totals']['net_sales'])->toBe($summary['net_sales'])
        ->and($profit['totals']['gross_profit'])->toBe('37000.00');
});

it('harga modal diubah setelah transaksi tidak mengubah laba lama', function () {
    profitSale($this->pos, [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);
    TenantContext::run($this->pos->tenantId, fn () => $this->pos->croissant->update(['cost_price' => '20000.00']));

    expect(collect(profitOf($this->pos)['products'])->firstWhere('product_name', 'Croissant')['cogs'])->toBe('9000.00');
});

it('produk tanpa harga modal: laba & margin null, dilaporkan terpisah, tidak masuk margin total', function () {
    TenantContext::run($this->pos->tenantId, fn () => $this->pos->coffee->update(['cost_price' => null]));
    profitSale($this->pos, [['product_id' => $this->pos->coffee->id, 'qty' => 2, 'option_ids' => [$this->pos->regular->id]], ['product_id' => $this->pos->croissant->id, 'qty' => 1]]);

    $profit = profitOf($this->pos);
    $coffee = collect($profit['products'])->firstWhere('product_name', 'Es Kopi Susu');

    expect($coffee['gross_profit'])->toBeNull()->and($coffee['margin'])->toBeNull()->and($coffee['uncosted_qty'])->toBe(2)
        ->and($profit['totals']['uncosted_qty'])->toBe(2)
        ->and($profit['totals']['costed_sales'])->toBe('24000.00')
        ->and($profit['totals']['gross_profit'])->toBe('15000.00')
        ->and($profit['totals']['margin'])->toBe('62.5');
});

it('API /reports/profit: owner boleh, supervisor ditolak, tenant lain kosong', function () {
    profitSale($this->pos, [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);

    $this->withToken(userToken($this->owner))->getJson('/api/v1/reports/profit?from=2026-10-08&to=2026-10-08', apiHeaders())
        ->assertOk()->assertJsonPath('data.totals.cogs', '9000.00')->assertJsonPath('data.products.0.product_name', 'Croissant');

    freshAuth();
    $this->withToken(userToken($this->pos->supervisor))->getJson('/api/v1/reports/profit?from=2026-10-08&to=2026-10-08', apiHeaders())
        ->assertForbidden();

    $other = posSetup();
    $otherOwner = User::factory()->owner()->create(['tenant_id' => $other->tenantId]);
    freshAuth();
    $this->withToken(userToken($otherOwner))->getJson('/api/v1/reports/profit?from=2026-10-08&to=2026-10-08', apiHeaders())
        ->assertOk()->assertJsonPath('data.products', [])->assertJsonPath('data.totals.cogs', '0.00');
});

it('tab Laba kotor: owner melihat, supervisor tidak; widget menampilkan total', function () {
    profitSale($this->pos, [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);
    $this->actingAs($this->owner);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    expect(ReportTab::Profit->visibleFor($this->owner))->toBeTrue()
        ->and(ReportTab::Profit->visibleFor($this->pos->supervisor))->toBeFalse();

    $range = ['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-08'];
    Livewire::test(ProfitStatsWidget::class, ['pageFilters' => $range])->assertSee('Rp9.000')->assertSee('Laba kotor');
    Livewire::test(ProfitTableWidget::class, ['pageFilters' => $range])->assertSee('Croissant')->assertSeeHtml('<tfoot>');
});
