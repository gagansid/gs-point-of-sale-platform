<?php

declare(strict_types=1);

use App\Actions\Admin\SaveBank;
use App\Actions\Admin\UpdateBillingMidtransSettings;
use App\Actions\Admin\UpdateBillingPaymentSettings;
use App\Filament\Admin\Pages\AuditLog;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Bank;
use App\Support\PlatformAudit;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Sistem → Riwayat perubahan (admin): setelan platform & master bank, rahasia disamarkan. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = Admin::factory()->withTwoFactor()->create(['name' => 'Rina Admin']);
    $this->actingAs($this->admin, 'admin');
    $this->bca = Bank::query()->where('code', 'bca')->sole();
});

it('ubah rekening tercatat sekali dengan pelaku & nilai sebelum → sesudah; simpan ulang tanpa perubahan tidak dicatat', function () {
    $data = ['accounts' => [['bank_id' => $this->bca->id, 'number' => '1234567890', 'holder' => 'PT Abati']], 'manual_note' => 'Jam kerja'];
    app(UpdateBillingPaymentSettings::class)->handle($this->admin, $data);
    app(UpdateBillingPaymentSettings::class)->handle($this->admin, $data);

    $logs = PlatformAudit::query()->with('causer')->where('log_name', PlatformAudit::LOG_NAME)->get();
    $accounts = $logs->firstWhere(fn (ActivityLog $l): bool => $l->properties['key'] === SystemSettings::BILLING_BANK_ACCOUNTS);

    expect($logs)->toHaveCount(2)
        ->and($accounts->causer?->is($this->admin))->toBeTrue()
        ->and($accounts->event)->toBe('created')
        ->and($accounts->tenant_id)->toBeNull()
        ->and(AuditLog::changes($accounts)[0])->toMatchArray(['label' => 'Rekening transfer manual', 'old' => '—', 'new' => 'BCA 1234567890 a.n. PT Abati']);
});

it('server key Midtrans tidak pernah tersimpan di riwayat, hanya disamarkan', function () {
    Http::fake(fn (Request $r) => Http::response(['status_code' => '404'], 404));
    app(UpdateBillingMidtransSettings::class)->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'Mid-server-rahasiaSekali01']);

    $raw = PlatformAudit::query()->get()->pluck('properties')->toJson();
    $keyLog = PlatformAudit::query()->get()->first(fn (ActivityLog $l): bool => str_starts_with((string) $l->properties['key'], SystemSettings::BILLING_MIDTRANS_KEY_PREFIX));

    expect($raw)->not->toContain('rahasiaSekali01')
        ->and(AuditLog::changes($keyLog)[0])->toMatchArray(['label' => 'Server key Midtrans Produksi', 'old' => '—'])
        ->and(AuditLog::changes($keyLog)[0]['new'])->toContain('••••');
});

it('perubahan bank tercatat atas nama admin; tidak terlihat di riwayat perubahan bisnis mana pun', function () {
    app(SaveBank::class)->handle($this->bca, [...$this->bca->only(['code', 'name', 'short_name', 'text_color']), 'color' => '#003399']);

    $log = PlatformAudit::query()->with('causer')->where('subject_type', Bank::class)->where('event', 'updated')->sole();
    expect($log->causer?->is($this->admin))->toBeTrue()
        ->and(AuditLog::changes($log))->toContain(['field' => 'color', 'label' => 'Warna merek', 'old' => '#0060af', 'new' => '#003399']);

    $pos = posSetup(openShift: false);
    TenantContext::run($pos->tenantId, fn () => expect(ActivityLog::query()->whereKey($log->id)->exists())->toBeFalse());
});

it('halaman Riwayat perubahan admin: daftar, filter menu, detail', function () {
    app(UpdateBillingPaymentSettings::class)->handle($this->admin, ['accounts' => [], 'manual_note' => 'Catatan baru']);
    app(SaveBank::class)->handle($this->bca, [...$this->bca->only(['code', 'name', 'short_name', 'color', 'text_color']), 'sort_order' => 9]);
    $setting = PlatformAudit::query()->where('log_name', PlatformAudit::LOG_NAME)->sole();
    $bank = PlatformAudit::query()->where('subject_type', Bank::class)->sole();

    Livewire::test(AuditLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$setting, $bank])
        ->assertSee('Petunjuk transfer manual')->assertSee('Rina Admin')
        ->filterTable('menu', 'bank')
        ->assertCanSeeTableRecords([$bank])->assertCanNotSeeTableRecords([$setting])
        ->mountAction(TestAction::make('detail')->table($bank))
        ->assertActionMounted(TestAction::make('detail')->table($bank));
});
