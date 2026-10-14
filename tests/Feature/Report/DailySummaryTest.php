<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Actions\Report\SendDailySummaries;
use App\Actions\Shift\CloseShift;
use App\Enums\TenantStatus;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DailySalesSummary;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Ringkasan harian lewat email (SPEC Q57, R5). Outlet Asia/Jakarta (UTC+7). */
function summarySale(stdClass $pos, string $utc): Order
{
    Carbon::setTestNow($utc);

    // 1 Croissant = 28.000 setelah service, pajak & pembulatan
    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order']);
}

function sendSummaries(string $utc): int
{
    return app(SendDailySummaries::class)->handle(CarbonImmutable::parse($utc));
}

beforeEach(function () {
    Notification::fake();
    $this->pos = posSetup();
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create(['name' => 'Rina']));

    // Kemarin = 7 Okt WIB: 2 penjualan, 1 void, shift ditutup kurang Rp5.000
    summarySale($this->pos, '2026-10-07 03:00:00');
    summarySale($this->pos, '2026-10-07 04:00:00');
    $voided = summarySale($this->pos, '2026-10-07 05:00:00');
    TenantContext::run($this->pos->tenantId, function () use ($voided): void {
        app(VoidOrder::class)->handle($this->pos->supervisor, $voided, 'Batal', null);
        // Kas seharusnya 200.000 + 56.000 = 256.000; dihitung 251.000
        app(CloseShift::class)->handle($this->pos->cashier, $this->pos->shift->refresh(), $this->pos->device, '251000', null, null);
        Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create(['opening_cash' => '200000.00']);
    });
    // Hari ini (8 Okt WIB) tidak ikut ringkasan kemarin
    summarySale($this->pos, '2026-10-08 00:30:00');
    Carbon::setTestNow();
});

it('owner menerima ringkasan kemarin setelah jam 07.00 waktu outlet; isi omzet, void, selisih kas', function () {
    expect(sendSummaries('2026-10-08 01:00:00'))->toBe(1);   // 08.00 WIB

    Notification::assertSentTo($this->owner, DailySalesSummary::class, function (DailySalesSummary $n): bool {
        $row = $n->summary['outlets'][0];

        return $n->summary['date'] === '2026-10-07'
            && $row['revenue'] === '56000.00' && $row['order_count'] === 2
            && $row['void_count'] === 1 && $row['void_total'] === '28000.00'
            && $row['cash_difference'] === '-5000.00';
    });
    Notification::assertNotSentTo([$this->pos->cashier, $this->pos->supervisor], DailySalesSummary::class);
    expect($this->owner->refresh()->last_daily_summary_on?->toDateString())->toBe('2026-10-08');
});

it('email berisi tabel outlet dan daftar yang perlu dicek', function () {
    $this->pos->outlet->update(['daily_sales_target' => '100000']);
    sendSummaries('2026-10-08 01:00:00');

    Notification::assertSentTo($this->owner, DailySalesSummary::class, function (DailySalesSummary $n): bool {
        $html = (string) $n->toMail($this->owner)->render();

        return str_contains($html, 'Rp56.000')
            && str_contains($html, 'selisih kas -Rp5.000')
            && str_contains($html, '1 transaksi di-void')
            && str_contains($html, 'target harian tercapai 56,0%')
            && str_contains($n->toMail($this->owner)->subject, 'Rabu, 7 Oktober 2026');
    });
});

it('sebelum jam 07.00 belum dikirim; dijalankan berulang tetap satu email per hari', function () {
    expect(sendSummaries('2026-10-07 23:30:00'))->toBe(0);   // 06.30 WIB
    Notification::assertNothingSent();

    expect(sendSummaries('2026-10-08 00:05:00'))->toBe(1)   // 07.05 WIB
        ->and(sendSummaries('2026-10-08 01:05:00'))->toBe(0)
        ->and(sendSummaries('2026-10-08 05:05:00'))->toBe(0);
    Notification::assertSentToTimes($this->owner, DailySalesSummary::class, 1);
});

it('tidak dikirim: opt-out, email belum terverifikasi, akun nonaktif, tenant diblokir, kasir walau opt-in', function (Closure $change) {
    $change($this);

    expect(sendSummaries('2026-10-08 01:00:00'))->toBe(0);
    Notification::assertNothingSent();
})->with([
    'opt-out' => [fn (object $t) => $t->owner->forceFill(['daily_summary_email' => false])->save()],
    'belum verifikasi' => [fn (object $t) => $t->owner->forceFill(['email_verified_at' => null])->save()],
    'nonaktif' => [fn (object $t) => $t->owner->forceFill(['is_active' => false])->save()],
    'tenant diblokir' => [fn (object $t) => Tenant::query()->whereKey($t->pos->tenantId)->update(['status' => TenantStatus::Suspended])],
    'kasir opt-in' => [function (object $t): void {
        $t->owner->forceFill(['daily_summary_email' => false])->save();
        $t->pos->cashier->forceFill(['daily_summary_email' => true])->save();
    }],
]);

it('tanpa aktivitas kemarin: tidak dikirim, tanggal tetap ditandai', function () {
    expect(sendSummaries('2026-10-10 01:00:00'))->toBe(0);   // kemarin = 9 Okt, sepi
    Notification::assertNothingSent();
    expect($this->owner->refresh()->last_daily_summary_on?->toDateString())->toBe('2026-10-10');
});

it('isolasi tenant: owner tenant lain hanya menerima data tenantnya', function () {
    $other = posSetup();
    $otherOwner = TenantContext::run($other->tenantId, fn () => User::factory()->owner()->create());
    summarySale($other, '2026-10-07 06:00:00');
    Carbon::setTestNow();

    expect(sendSummaries('2026-10-08 01:00:00'))->toBe(2);

    Notification::assertSentTo($otherOwner, DailySalesSummary::class, fn (DailySalesSummary $n): bool => $n->summary['totals']['revenue'] === '28000.00'
        && $n->summary['totals']['void_count'] === 0
        && collect($n->summary['outlets'])->pluck('name')->all() === [$other->outlet->name]);
    Notification::assertSentTo($this->owner, DailySalesSummary::class, fn (DailySalesSummary $n): bool => $n->summary['totals']['revenue'] === '56000.00');
});

it('owner baru otomatis berlangganan; manager & kasir tidak', function () {
    TenantContext::run($this->pos->tenantId, function (): void {
        expect(User::factory()->owner()->create()->daily_summary_email)->toBeTrue()
            ->and(User::factory()->manager()->create()->daily_summary_email)->toBeFalse()
            ->and(User::factory()->owner()->create(['daily_summary_email' => false])->daily_summary_email)->toBeFalse();
    });
});

it('toggle di Profil: tampil & tersimpan untuk owner', function () {
    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    Livewire::test(EditProfile::class)
        ->assertSee('Kirim ringkasan harian lewat email')
        ->assertSet('data.daily_summary_email', true)
        ->fillForm(['daily_summary_email' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->owner->refresh()->daily_summary_email)->toBeFalse();
});

it('command pos:daily-summary berjalan', function () {
    Carbon::setTestNow('2026-10-08 01:00:00');
    $this->artisan('pos:daily-summary')->expectsOutputToContain('1 ringkasan harian diantrekan')->assertSuccessful();
    Carbon::setTestNow();
});

it('outlet nonaktif tidak ikut ringkasan', function () {
    $closed = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create(['name' => 'Outlet Tutup', 'is_active' => false]));
    sendSummaries('2026-10-08 01:00:00');

    Notification::assertSentTo($this->owner, DailySalesSummary::class,
        fn (DailySalesSummary $n): bool => ! in_array($closed->name, array_column($n->summary['outlets'], 'name'), true));
});
