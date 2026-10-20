<?php

declare(strict_types=1);

use App\Actions\Product\SetProductAvailability;
use App\Filament\Dashboard\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Dashboard\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Livewire\Cashier\PointOfSale;
use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/** Menu habis/tersedia per outlet tercatat di Riwayat perubahan (Q58, Q115). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->pos->outlet->forceFill(['name' => 'Kemang'])->saveQuietly();
});

/** @return Collection<int, ActivityLog> */
function availabilityLogs(string $tenantId): Collection
{
    return TenantContext::run($tenantId, fn () => ActivityLog::query()
        ->where('event', SetProductAvailability::LOG_EVENT)->orderBy('id')->get());
}

function markViaApi(object $test, string $token, string $productId, bool $available): void
{
    freshAuth();
    $test->withToken($token)
        ->patchJson("/api/v1/products/{$productId}/availability", ['is_available' => $available], apiHeaders())
        ->assertOk();
}

it('API: kasir menandai habis → log berisi pelaku, outlet, produk, nilai lama → baru', function () {
    markViaApi($this, $this->pos->token, $this->pos->croissant->id, false);

    $log = availabilityLogs($this->pos->tenantId)->sole();
    $stock = TenantContext::run($this->pos->tenantId, fn () => ProductStock::for($this->pos->croissant->id, $this->pos->outlet->id));

    expect($log->causer?->is($this->pos->cashier))->toBeTrue()
        ->and($log->tenant_id)->toBe($this->pos->tenantId)
        ->and($log->subject_type)->toBe(ProductStock::class)
        ->and($log->subject_id)->toBe($stock->id)
        ->and($log->description)->toBe('Croissant ditandai habis di Kemang oleh Budi')
        ->and($log->properties['old'])->toBe(['is_available' => true])
        ->and($log->properties['attributes'])->toBe(['is_available' => false])
        ->and($log->properties['outlet_id'])->toBe($this->pos->outlet->id)
        ->and($log->properties['product_id'])->toBe($this->pos->croissant->id)
        ->and(ActivityLogResource::summary($log))->toBe('Croissant ditandai habis di Kemang oleh Budi');
});

it('idempoten: menandai ulang dengan nilai sama tidak membuat log ganda', function () {
    markViaApi($this, $this->pos->token, $this->pos->croissant->id, false);
    markViaApi($this, $this->pos->token, $this->pos->croissant->id, false);
    expect(availabilityLogs($this->pos->tenantId))->toHaveCount(1);

    // Menandai "tersedia" pada menu yang memang tersedia juga tidak dicatat
    TenantContext::run($this->pos->tenantId, fn () => app(SetProductAvailability::class)
        ->handle($this->pos->coffee, $this->pos->outlet, true));
    expect(availabilityLogs($this->pos->tenantId))->toHaveCount(1);

    markViaApi($this, $this->pos->token, $this->pos->croissant->id, true);
    $logs = availabilityLogs($this->pos->tenantId);
    expect($logs)->toHaveCount(2)
        ->and($logs->last()?->description)->toBe('Croissant ditandai tersedia di Kemang oleh Budi');
});

it('kasir web: toggle dari tile tercatat atas nama kasir yang login', function () {
    $device = webCashierLogin($this, $this->pos, $this->pos->supervisor);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->supervisor, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->supervisor)->create(['opening_cash' => '100000.00']);

    Livewire::test(PointOfSale::class)->call('toggleAvailability', $this->pos->croissant->id);

    $log = availabilityLogs($this->pos->tenantId)->sole();
    expect($log->causer?->is($this->pos->supervisor))->toBeTrue()
        ->and($log->description)->toBe('Croissant ditandai habis di Kemang oleh Sari');
});

it('isolasi tenant: log tenant lain tidak terlihat di Riwayat perubahan', function () {
    $foreign = posSetup();
    markViaApi($this, $foreign->token, $foreign->croissant->id, false);
    markViaApi($this, $this->pos->token, $this->pos->croissant->id, false);

    expect(availabilityLogs($this->pos->tenantId))->toHaveCount(1)
        ->and(availabilityLogs($this->pos->tenantId)->sole()->causer?->is($this->pos->cashier))->toBeTrue();

    freshAuth();
    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->pos->tenantId);
    $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId, 'name' => 'Rina']);
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords(availabilityLogs($this->pos->tenantId))
        ->assertCanNotSeeTableRecords(availabilityLogs($foreign->tenantId))
        ->assertSee('Ketersediaan menu')
        ->assertSee('Croissant · Kemang')
        ->assertSee('Habis');
});

it('dashboard: owner bisa mencari; manager hanya melihat outlet yang dipegangnya', function () {
    $other = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create(['name' => 'Bintaro']));
    markViaApi($this, $this->pos->token, $this->pos->croissant->id, false);
    freshAuth();
    TenantContext::run($this->pos->tenantId, fn () => app(SetProductAvailability::class)
        ->handle($this->pos->coffee, $other, false));

    $logs = availabilityLogs($this->pos->tenantId);
    $kemang = $logs->filter(fn (ActivityLog $l) => $l->properties['outlet_id'] === $this->pos->outlet->id);
    $bintaro = $logs->filter(fn (ActivityLog $l) => $l->properties['outlet_id'] === $other->id);
    expect($kemang)->toHaveCount(1)->and($bintaro)->toHaveCount(1);

    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->pos->tenantId);
    $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($owner);
    CurrentOutlet::set($owner);
    Livewire::test(ListActivityLogs::class)
        ->searchTable('croissant')
        ->assertCanSeeTableRecords($kemang)
        ->assertCanNotSeeTableRecords($bintaro);

    $manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
    $this->actingAs($manager);
    CurrentOutlet::set($manager);
    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords($kemang)
        ->assertCanNotSeeTableRecords($bintaro);
});
