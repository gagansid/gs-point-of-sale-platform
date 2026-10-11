<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\QueueMonitor;
use App\Models\Admin;
use App\Models\FailedJob;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Sistem → Queue: job gagal (lihat, ulangi, hapus) & jadwal cron. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

function failJob(string $name = 'App\\Notifications\\InviteOwner'): FailedJob
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid = (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [], 'uuid' => $uuid, 'attempts' => 3]),
        'exception' => "RuntimeException: SMTP timeout\n#0 /app/x.php(1)",
        'failed_at' => now(),
    ]);

    return FailedJob::query()->where('uuid', $uuid)->sole();
}

it('menampilkan job gagal dengan nama & ringkasan error; badge menu', function () {
    $job = failJob();

    expect(QueueMonitor::getNavigationBadge())->toBe('1');
    Livewire::test(QueueMonitor::class)->assertOk()
        ->assertCanSeeTableRecords([$job])
        ->assertSee('InviteOwner')->assertSee('RuntimeException: SMTP timeout');
});

it('ulangi: job pindah ke antrean; hapus: job hilang', function () {
    $retry = failJob();
    $forget = failJob('App\\Jobs\\Lain');

    Livewire::test(QueueMonitor::class)
        ->callAction(TestAction::make('retry')->table($retry))
        ->callAction(TestAction::make('forget')->table($forget));

    expect(FailedJob::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('hapus semua', function () {
    failJob();
    failJob();

    Livewire::test(QueueMonitor::class)->callAction('flush');
    expect(FailedJob::query()->count())->toBe(0);
});

it('jadwal cron: queue tiap menit, prune token harian', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression]);

    expect($commands->first(fn ($c) => str_contains((string) $c[0], 'queue:work --stop-when-empty'))[1])->toBe('* * * * *')
        ->and($commands->contains(fn ($c) => str_contains((string) $c[0], 'sanctum:prune-expired')))->toBeTrue();
});
