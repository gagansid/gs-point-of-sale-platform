<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Exports\SafeCell;
use App\Exports\SalesReportExport;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Resources\Orders\Pages\ListOrders;
use App\Filament\Dashboard\Resources\Orders\Pages\ViewOrder;
use App\Filament\Dashboard\Resources\Shifts\Pages\ListShifts;
use App\Filament\Dashboard\Widgets\SalesStatsWidget;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    TenantContext::set($this->pos->tenantId);

    $this->order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order'];

    $this->actingAs($this->owner);
});

it('beranda menampilkan omzet hari ini', function () {
    $this->get('/dashboard')->assertOk()->assertSee('Omzet hari ini')->assertSee('Rp28.000')->assertSee('Produk terlaris');
});

it('widget laporan disembunyikan untuk yang tanpa report.view', function () {
    $this->actingAs(User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]));
    expect(SalesStatsWidget::canView())->toBeTrue();

    $this->actingAs($this->pos->supervisor);
    expect(SalesStatsWidget::canView())->toBeFalse();
});

it('daftar penjualan hari ini hanya tenant sendiri, detail bisa dibuka', function () {
    $other = posSetup();
    TenantContext::set($this->pos->tenantId);
    $foreignOrders = Order::allTenants()->where('tenant_id', $other->tenantId)->get();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$this->order])
        ->assertCanNotSeeTableRecords($foreignOrders);

    $this->get("/dashboard/orders/{$this->order->id}")->assertOk()->assertSee($this->order->order_number)->assertSee('Croissant');
});

it('void dari dashboard mengembalikan stok', function () {
    Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
        ->callAction('void', ['reason' => 'Komplain pelanggan'])
        ->assertHasNoActionErrors();

    expect($this->order->refresh()->status->value)->toBe('voided')
        ->and(Product::query()->find($this->pos->croissant->id)?->stock_qty)->toBe(10);
});

it('tutup paksa shift dari dashboard', function () {
    Livewire::test(ListShifts::class)
        ->callAction(TestAction::make('forceClose')->table($this->pos->shift), ['actual_cash' => '228.000', 'note' => 'Lupa tutup'])
        ->assertHasNoActionErrors();

    $shift = $this->pos->shift->refresh();
    expect($shift->status->value)->toBe('force_closed')
        ->and($shift->expected_cash)->toBe('228000.00')
        ->and($shift->difference)->toBe('0.00');
});

it('halaman laporan & export Excel', function () {
    Excel::fake();

    Livewire::test(Reports::class)
        ->assertSee('Rp28.000')
        ->assertSee('Croissant')
        ->callAction('export');

    Excel::assertDownloaded('laporan-penjualan-'.now('Asia/Jakarta')->startOfMonth()->toDateString().'-'.now('Asia/Jakarta')->toDateString().'.xlsx',
        fn (SalesReportExport $export): bool => count($export->sheets()) === 4);
});

it('export & halaman laporan tertutup untuk supervisor', function () {
    $this->actingAs($this->pos->supervisor);

    expect(Reports::canAccess())->toBeFalse();
});

it('export mencegah formula injection dari nama produk', function (string $name, string $expected) {
    expect(SafeCell::text($name))->toBe($expected);
})->with([
    ['=HYPERLINK("http://jahat")', '\'=HYPERLINK("http://jahat")'],
    ['+62812', "'+62812"],
    ['-1+1', "'-1+1"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ['Es Kopi Susu', 'Es Kopi Susu'],
]);
