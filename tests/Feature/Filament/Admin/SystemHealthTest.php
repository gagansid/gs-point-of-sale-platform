<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\QueueMonitor;
use App\Filament\Admin\Widgets\QueuedJobsWidget;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\Admin;
use App\Services\Admin\SystemHealth;
use App\Support\QueuedJobLabel;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Dasbor /admin (D4): kesehatan sistem — heartbeat scheduler, antrean & tabel job, job gagal, lingkungan. */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 03:00:00');
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

afterEach(fn () => Carbon::setTestNow());

/** Payload job database seperti yang ditulis Laravel (hanya bagian yang dibaca). */
function healthJob(string $displayName, string $command = '', array $override = []): void
{
    $now = now()->getTimestamp();
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName, 'data' => ['commandName' => $displayName, 'command' => $command]]),
        'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now,
        ...$override,
    ]);
}

it('label job: kelas dikenal, notifikasi dari command, tidak dikenal, payload rusak', function () {
    $notification = 'O:48:"Illuminate\\Notifications\\SendQueuedNotifications":3:{s:13:"notification";O:32:"App\\Notifications\\InvoiceCreated":1:{s:6:"number";s:3:"INV";}}';

    expect(QueuedJobLabel::fromPayload(json_encode(['displayName' => 'App\\Jobs\\RunBackup'])))->toBe(['label' => 'Backup database & file', 'class' => 'App\\Jobs\\RunBackup'])
        ->and(QueuedJobLabel::fromPayload(json_encode(['displayName' => 'Illuminate\\Notifications\\SendQueuedNotifications', 'data' => ['command' => $notification]]))['label'])->toBe('Email tagihan langganan baru')
        ->and(QueuedJobLabel::fromPayload(json_encode(['displayName' => 'Vendor\\Pkg\\SyncThing']))['label'])->toBe('SyncThing')
        ->and(QueuedJobLabel::fromPayload('bukan json')['label'])->toBe('Tidak diketahui');
});

it('heartbeat scheduler: belum pernah → tidak sehat; berdetak → sehat; > 3 menit → tidak sehat', function () {
    $health = app(SystemHealth::class);
    expect($health->scheduler()['healthy'])->toBeFalse();

    SystemHealth::beat();
    expect($health->scheduler()['healthy'])->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(4));
    expect($health->scheduler()['healthy'])->toBeFalse();
});

it('heartbeat terdaftar di jadwal tiap menit', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'scheduler-heartbeat');

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

it('hitungan antrean & urutan tabel job: diproses dulu, lalu menunggu terlama, lalu terjadwal', function () {
    healthJob('App\\Jobs\\RunBackup', override: ['created_at' => now()->subMinutes(10)->getTimestamp(), 'available_at' => now()->subMinutes(10)->getTimestamp()]);
    healthJob('App\\Jobs\\RunBackup', override: ['available_at' => now()->addHour()->getTimestamp()]);
    healthJob('Illuminate\\Notifications\\SendQueuedNotifications', 'O:40:"App\\Notifications\\DailySalesSummary":0:{}', ['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
    DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Error', 'failed_at' => now()->subHours(2)]);

    $health = app(SystemHealth::class);
    $queue = $health->queue();
    expect($queue['pending'])->toBe(1)
        ->and($queue['processing'])->toBe(1)
        ->and($queue['delayed'])->toBe(1)
        ->and($queue['failed'])->toBe(1)
        ->and($queue['failed_24h'])->toBe(1)
        ->and($queue['oldest']->equalTo(now()->subMinutes(10)))->toBeTrue()
        ->and(array_map(fn (array $j): array => [$j['label'], $j['status']], $health->jobs()))->toBe([
            ['Email ringkasan penjualan harian', 'processing'],
            ['Backup database & file', 'pending'],
            ['Backup database & file', 'delayed'],
        ]);
});

it('tabel job tampil di Dasbor & Queue tanpa membuka isi data job', function () {
    // Data job (mis. email penerima) tidak boleh tampil
    healthJob('Illuminate\\Notifications\\SendQueuedNotifications', 'O:34:"App\\Notifications\\InviteOwner":1:{s:5:"email";s:16:"owner@kopi.test";}', ['reserved_at' => now()->getTimestamp()]);

    Livewire::test(QueuedJobsWidget::class)
        ->assertOk()
        ->assertSee('Job di antrean')
        ->assertSee('Email undangan owner')
        ->assertSee('Diproses')
        ->assertSee('Buka Queue')
        ->assertDontSee('owner@kopi.test');

    Livewire::test(QueuedJobsWidget::class, ['showQueueLink' => false])->assertDontSee('Buka Queue');
    $this->get(QueueMonitor::getUrl())->assertOk()->assertSee('Job di antrean');
});

it('widget kesehatan: cron, antrean, job gagal, mode perawatan, lingkungan', function () {
    Livewire::test(SystemHealthWidget::class)
        ->assertOk()
        ->assertSee('Kesehatan sistem')
        ->assertSee('Tidak berjalan')
        ->assertSee('Antrean menunggu')
        ->assertSee('Mode perawatan')
        ->assertSee('PHP '.PHP_VERSION);

    SystemHealth::beat();
    Livewire::test(SystemHealthWidget::class)->assertSee('Berjalan')->assertDontSee('Tidak berjalan');
});
