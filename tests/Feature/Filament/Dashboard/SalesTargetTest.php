<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Widgets\SalesTargetWidget;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Target omzet harian & bulanan per outlet dengan progres di Beranda (SPEC Q56, R6). */
function targetSale(stdClass $pos, string $utc): Order
{
    Carbon::setTestNow($utc);

    // 1 Croissant = 28.000 setelah service, pajak & pembulatan
    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order']);
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

    targetSale($this->pos, '2026-09-30 10:00:00');   // bulan lalu (30 Sep 17.00 WIB)
    targetSale($this->pos, '2026-10-07 03:00:00');   // kemarin
    targetSale($this->pos, '2026-10-08 02:00:00');   // hari ini
    targetSale($this->pos, '2026-10-08 05:00:00');   // hari ini
    $voided = targetSale($this->pos, '2026-10-08 06:00:00');
    TenantContext::run($this->pos->tenantId, fn () => app(VoidOrder::class)->handle($this->pos->supervisor, $voided, 'Batal', null));

    // 8 Okt 17.00 WIB: 8 dari 31 hari = 25,8% bulan berlalu
    Carbon::setTestNow('2026-10-08 10:00:00');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('progres hari ini & bulan berjalan; void dan bulan lalu tidak dihitung', function () {
    $this->pos->outlet->update(['daily_sales_target' => '100000', 'monthly_sales_target' => '1000000']);

    expect(app(ReportService::class)->salesTargets())->toBe([[
        'outlet_id' => $this->pos->outlet->id,
        'name' => $this->pos->outlet->name,
        'daily_target' => '100000.00',
        'daily_revenue' => '56000.00',
        'daily_percent' => '56.0',
        'monthly_target' => '1000000.00',
        'monthly_revenue' => '84000.00',
        'monthly_percent' => '8.4',
        'month_pace' => '25.8',
    ]]);
});

it('widget Beranda: persen, nominal, dan status jalur', function () {
    $this->pos->outlet->update(['daily_sales_target' => '50000', 'monthly_sales_target' => '1000000']);

    Livewire::test(SalesTargetWidget::class)
        ->assertSee('Target omzet')
        ->assertSee('112,0%')                    // 56.000 dari 50.000 → tercapai
        ->assertSee('Tercapai')
        ->assertSee('8,4%')
        ->assertSee('Di bawah jalur (25,8% bulan berlalu)')
        ->assertSee('Rp84.000 dari Rp1.000.000');

    $this->get('/dashboard')->assertOk()->assertSee('Target omzet');
});

it('hanya target harian: baris bulanan tidak tampil', function () {
    $this->pos->outlet->update(['daily_sales_target' => '100000']);

    Livewire::test(SalesTargetWidget::class)
        ->assertSee('Hari ini')->assertSee('Kurang Rp44.000')
        ->assertDontSee('Bulan ini');
});

it('belum ada target: ajakan untuk owner, manager tidak melihat apa pun', function () {
    Livewire::test(SalesTargetWidget::class)->assertSee('Belum ada target')->assertSee('Atur di Profil outlet');

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create(['tenant_id' => $this->pos->tenantId]));
    Livewire::test(SalesTargetWidget::class)->assertDontSee('Target omzet');
});

it('isolasi tenant: target outlet tenant lain tidak ikut', function () {
    // Dibuat di luar konteks tenant: BelongsToTenant tidak menimpa tenant_id dari factory
    TenantContext::forget();
    $other = Outlet::factory()->create(['name' => 'Outlet Tetangga', 'daily_sales_target' => '1000']);
    TenantContext::set($this->pos->tenantId);
    expect($other->tenant_id)->not->toBe($this->pos->tenantId);

    Livewire::test(SalesTargetWidget::class)->assertDontSee('Outlet Tetangga')->assertSee('Belum ada target');
    expect(app(ReportService::class)->salesTargets())->toBe([]);
});

it('kasir tanpa izin laporan tidak melihat widget', function () {
    expect(SalesTargetWidget::canView())->toBeTrue();

    $this->actingAs($this->pos->cashier);
    expect(SalesTargetWidget::canView())->toBeFalse();
});

it('owner mengatur target di Profil outlet; kosong/0 = tanpa target; negatif ditolak', function () {
    Livewire::test(OutletSettings::class)
        ->set('data.daily_sales_target', '3000000')
        ->set('data.monthly_sales_target', '90000000')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->pos->outlet->refresh()->daily_sales_target)->toBe('3000000.00')
        ->and($this->pos->outlet->monthly_sales_target)->toBe('90000000.00');

    Livewire::test(OutletSettings::class)
        ->set('data.daily_sales_target', '')
        ->set('data.monthly_sales_target', '0')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->pos->outlet->refresh()->daily_sales_target)->toBeNull()
        ->and($this->pos->outlet->monthly_sales_target)->toBeNull();

    Livewire::test(OutletSettings::class)
        ->set('data.daily_sales_target', '-5')
        ->call('save')
        ->assertHasFormErrors(['daily_sales_target']);
});

it('API: GET/PUT /v1/outlet memuat target; kasir ditolak; request ganda tetap satu nilai', function () {
    $token = userToken($this->owner, $this->pos->device);

    foreach (range(1, 2) as $i) {
        freshAuth();
        $this->withToken($token)->putJson('/api/v1/outlet', ['daily_sales_target' => '2500000'], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.daily_sales_target', '2500000.00')
            ->assertJsonPath('data.monthly_sales_target', null);
    }

    freshAuth();
    $this->withToken($token)->putJson('/api/v1/outlet', ['monthly_sales_target' => 'banyak'], apiHeaders())
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->putJson('/api/v1/outlet', ['daily_sales_target' => '1'], apiHeaders()), 'FORBIDDEN', 403);

    expect($this->pos->outlet->refresh()->daily_sales_target)->toBe('2500000.00');
});
