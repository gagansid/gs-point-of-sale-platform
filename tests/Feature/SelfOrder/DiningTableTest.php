<?php

declare(strict_types=1);

use App\Actions\SelfOrder\RegenerateTableQr;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Filament\Dashboard\Resources\DiningTables\DiningTableResource;
use App\Filament\Dashboard\Resources\DiningTables\Pages\ManageDiningTables;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Self-order S1 (ADR 0014, Q69): meja & QR, setelan self-order per outlet, halaman QR publik. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang', 'timezone' => 'Asia/Jakarta']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner, $this->outlet->id);
});

afterEach(fn () => Carbon::setTestNow());

it('owner menambah meja; nama unik per outlet; token QR acak 32 karakter', function () {
    Livewire::test(ManageDiningTables::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Meja 1', 'area' => 'Teras'])
        ->assertHasNoActionErrors();

    $table = DiningTable::query()->sole();
    expect($table->outlet_id)->toBe($this->outlet->id)->and(strlen($table->qr_token))->toBe(32)
        ->and($table->orderUrl())->toEndWith('/order/t/'.$table->qr_token);

    Livewire::test(ManageDiningTables::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'meja 1'])
        ->assertNotified('Nama meja sudah dipakai di outlet ini');
    expect(DiningTable::query()->count())->toBe(1);
});

it('ganti QR: token lama tidak berlaku (404), token baru bisa dibuka', function () {
    $table = app(SaveDiningTable::class)->handle(null, $this->outlet, ['name' => 'Meja 1']);
    $old = $table->orderUrl();

    app(RegenerateTableQr::class)->handle($table);

    $this->get($old)->assertNotFound();
    $this->get($table->refresh()->orderUrl())->assertOk()->assertSee('Meja 1');
});

it('halaman QR publik: token salah/meja nonaktif 404; self-order nonaktif & di luar jam tampil pesan', function () {
    $table = app(SaveDiningTable::class)->handle(null, $this->outlet, ['name' => 'Meja 7']);
    auth()->logout();
    TenantContext::forget();
    CurrentOutlet::forget();

    $this->get('/order/t/'.str_repeat('x', 32))->assertNotFound();
    $this->get($table->orderUrl())->assertOk()->assertSee('belum aktif');

    TenantContext::run($this->outlet->tenant_id, fn () => app(UpdateSelfOrderSettings::class)->handle($this->outlet, ['enabled' => true, 'opens_at' => '08:00', 'closes_at' => '22:00']));
    Carbon::setTestNow('2026-10-08 16:00:00'); // 23.00 WIB
    $this->get($table->orderUrl())->assertOk()->assertSee('Di luar jam layanan');

    TenantContext::run($this->outlet->tenant_id, fn () => $table->forceFill(['is_active' => false])->save());
    $this->get($table->orderUrl())->assertNotFound();
});

it('setelan self-order: tersimpan per outlet; validasi jam', function () {
    Livewire::test(ManageDiningTables::class)
        ->callAction('selfOrderSettings', ['enabled' => true, 'payment_timeout_minutes' => 20, 'opens_at' => '07:00', 'closes_at' => '21:00', 'allow_pay_at_cashier' => true])
        ->assertNotified('Setelan self-order disimpan');

    $setting = SelfOrderSetting::forOutlet($this->outlet->id);
    expect($setting->enabled)->toBeTrue()->and($setting->payment_timeout_minutes)->toBe(20)->and($setting->opens_at)->toBe('07:00:00');

    expect(fn () => app(UpdateSelfOrderSettings::class)->handle($this->outlet, ['enabled' => true, 'payment_timeout_minutes' => 1]))
        ->toThrow(ValidationException::class);
});

it('cetak QR: hanya meja aktif outlet yang boleh diakses; kasir & supervisor tidak punya menu', function () {
    app(SaveDiningTable::class)->handle(null, $this->outlet, ['name' => 'Meja A']);
    $other = Outlet::factory()->create(['tenant_id' => $this->outlet->tenant_id, 'name' => 'Cilandak']);
    app(SaveDiningTable::class)->handle(null, $other, ['name' => 'Meja Cilandak']);

    $manager = User::factory()->manager()->forOutlet($this->outlet)->create();
    $this->actingAs($manager);
    CurrentOutlet::set($manager);

    $this->get(route('filament.dashboard.tables.print'))->assertOk()->assertSee('Meja A')->assertDontSee('Meja Cilandak')->assertSee('<svg', false);

    $supervisor = User::factory()->supervisor()->forOutlet($this->outlet)->create();
    $this->actingAs($supervisor);
    expect(DiningTableResource::canViewAny())->toBeFalse();
    $this->get(route('filament.dashboard.tables.print'))->assertForbidden();
});

it('isolasi tenant: meja bisnis lain tidak terlihat dan tidak bisa diubah', function () {
    TenantContext::forget();
    $foreignOutlet = Outlet::factory()->create();
    $foreign = TenantContext::run($foreignOutlet->tenant_id, fn () => DiningTable::query()->create(['outlet_id' => $foreignOutlet->id, 'name' => 'Asing']));
    TenantContext::set($this->outlet->tenant_id);

    expect(DiningTable::query()->count())->toBe(0);
    Livewire::test(ManageDiningTables::class)->assertCanNotSeeTableRecords([$foreign]);

    expect(fn () => app(SaveDiningTable::class)->handle(null, $this->outlet, ['name' => '']))->toThrow(ValidationException::class);
});
