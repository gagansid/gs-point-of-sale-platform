<?php

declare(strict_types=1);

use App\Actions\Admin\ManageBackups;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Models\Admin;
use App\Models\SalesLead;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\AdminAlerts;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Lonceng peringatan /admin (Q135): dihitung langsung, tautan membuka tab yang tepat. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    Storage::fake(ManageBackups::DISK);
    Carbon::setTestNow('2026-10-23 03:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** Backup palsu di disk backup, dibuat $hoursAgo jam lalu. */
function fakeBackup(int $hoursAgo): void
{
    $path = config('backup.backup.name').'/backup-'.$hoursAgo.'.zip';
    Storage::disk(ManageBackups::DISK)->put($path, 'zip');
    touch(Storage::disk(ManageBackups::DISK)->path($path), now()->subHours($hoursAgo)->getTimestamp());
}

/** Tagihan menunggu untuk bisnis baru. */
function pendingInvoice(): SubscriptionInvoice
{
    $tenant = Tenant::factory()->create();
    $plan = app(SavePlan::class)->handle(null, ['code' => 'p'.Str::lower(Str::random(6)), 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000']);

    return TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant, $plan, BillingCycle::Monthly));
}

/** @return list<string> */
function adminAlertKeys(): array
{
    return array_column(AdminAlerts::all(), 'key');
}

it('tanpa masalah (backup baru): tidak ada peringatan; lonceng tampil "Semua aman"', function () {
    fakeBackup(2);

    expect(AdminAlerts::all())->toBe([]);
    $this->get(Dashboard::getUrl())->assertOk()->assertSee('gs-alerts-trigger', false)->assertSee('Semua aman');
});

it('bukti transfer menunggu, tagihan lewat jatuh tempo, calon pelanggan baru', function () {
    fakeBackup(2);
    pendingInvoice()->forceFill(['proof_submitted_at' => now(), 'proof_sender' => 'Rina'])->save();
    pendingInvoice()->forceFill(['due_at' => now()->subDay()])->save();
    SalesLead::factory()->create(['status' => SalesLeadStatus::New]);
    SalesLead::factory()->create(['status' => SalesLeadStatus::Contacted]);

    $alerts = collect(AdminAlerts::all())->keyBy('key');

    expect($alerts->keys()->all())->toBe(['proofs', 'overdue', 'leads'])
        ->and($alerts['proofs']['title'])->toBe('1 bukti transfer menunggu verifikasi')
        ->and($alerts['proofs']['url'])->toEndWith('?tab=verify')
        ->and($alerts['overdue']['url'])->toEndWith('?tab=overdue')
        ->and($alerts['leads']['title'])->toBe('1 calon pelanggan baru');

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('1 bukti transfer menunggu verifikasi')->assertSee('gs-alerts-badge', false);
});

it('job gagal, backup usang/belum ada, mode pemeliharaan (paling atas)', function () {
    expect(adminAlertKeys())->toBe(['backup'])
        ->and(AdminAlerts::all()[0]['title'])->toBe('Belum ada backup');

    fakeBackup(30);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
    ]);
    app()->maintenanceMode()->activate([]);

    try {
        expect(adminAlertKeys())->toBe(['maintenance', 'failed_jobs', 'backup'])
            ->and(collect(AdminAlerts::all())->firstWhere('key', 'backup')['title'])->toBe('Backup terakhir lebih dari 1 hari');
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

it('edisi jual putus: tanpa peringatan tagihan & calon pelanggan', function () {
    fakeBackup(2);
    pendingInvoice()->forceFill(['due_at' => now()->subDay()])->save();
    config(['pos.edition' => 'self_hosted']);

    expect(adminAlertKeys())->not->toContain('overdue')->not->toContain('leads');
});

it('tautan ?tab= membuka tab yang tepat sekali, lalu tersimpan di session; kunci asing diabaikan', function () {
    Livewire::withQueryParams(['tab' => 'verify'])->test(ListSubscriptionInvoices::class)->assertSet('activeTab', 'verify');
    // Dibuka lagi tanpa ?tab: tab terakhir diingat
    Livewire::test(ListSubscriptionInvoices::class)->assertSet('activeTab', 'verify');
    // Kunci tidak dikenal tidak mengubah apa pun
    Livewire::withQueryParams(['tab' => '<script>'])->test(ListSubscriptionInvoices::class)->assertSet('activeTab', 'verify');
});
