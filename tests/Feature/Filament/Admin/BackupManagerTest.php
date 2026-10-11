<?php

declare(strict_types=1);

use App\Actions\Admin\ManageBackups;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Pages\BackupManager;
use App\Jobs\RunBackup;
use App\Models\Admin;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Sistem → Backup. Disk "backups" dipalsukan agar backup lokal tidak tersentuh. */
beforeEach(function () {
    Storage::fake('backups');
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(['password' => 'Rahasia-Admin-1!']), 'admin');
    Storage::disk('backups')->put('gspos/2026-10-10-02-00-00.zip', 'lama');
    Storage::disk('backups')->put('gspos/2026-10-11-02-00-00.zip', 'baru-isi');
    touch(Storage::disk('backups')->path('gspos/2026-10-10-02-00-00.zip'), now()->subDay()->timestamp);
});

it('daftar backup terbaru dulu', function () {
    expect(array_column(app(ManageBackups::class)->list(), 'name'))->toBe(['2026-10-11-02-00-00.zip', '2026-10-10-02-00-00.zip']);
    Livewire::test(BackupManager::class)->assertOk()->assertSee('2026-10-11-02-00-00.zip');
});

it('backup sekarang masuk antrean (bukan di request web)', function () {
    Queue::fake();
    Livewire::test(BackupManager::class)->callAction('run');
    Queue::assertPushed(RunBackup::class);
});

it('unduh & hapus hanya file dari daftar', function () {
    Livewire::test(BackupManager::class)->call('download', '2026-10-11-02-00-00.zip')->assertFileDownloaded('2026-10-11-02-00-00.zip');

    expect(fn () => app(ManageBackups::class)->path('../../../.env'))->toThrow(BusinessException::class);

    Livewire::test(BackupManager::class)
        ->callAction(TestAction::make('delete')->arguments(['name' => '2026-10-10-02-00-00.zip']), ['current_password' => 'Rahasia-Admin-1!']);
    Storage::disk('backups')->assertMissing('gspos/2026-10-10-02-00-00.zip');
    Storage::disk('backups')->assertExists('gspos/2026-10-11-02-00-00.zip');
});

it('jadwal backup harian', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

    expect($commands)->toContain('backup:run')->toContain('backup:clean')->toContain('backup:monitor');
});
