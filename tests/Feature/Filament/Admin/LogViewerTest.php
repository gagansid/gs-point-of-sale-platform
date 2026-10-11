<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\LogViewer;
use App\Models\Admin;
use App\Support\LogReader;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Sistem → Log. Memakai file log sementara agar laravel.log lokal tidak tersentuh. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(['password' => 'Rahasia-Admin-1!']), 'admin');
    $this->file = 'zz-test-'.uniqid().'.log';
    $this->path = storage_path('logs/'.$this->file);
    file_put_contents($this->path, implode("\n", [
        '[2026-10-11 10:00:00] local.INFO: Login owner {"user":"1"}',
        '[2026-10-11 10:01:00] local.ERROR: SMTP gagal <script>x</script> {"exception":"..."}',
        '#0 /app/Mail.php(10): send()',
        '[2026-10-11 10:02:00] local.WARNING: Stok menipis',
    ])."\n");
    touch($this->path, time() + 60);
});

afterEach(fn () => @unlink($this->path));

it('entri terbaru dulu, stack trace ikut entrinya, teks di-escape', function () {
    $entries = LogReader::entries($this->path);

    expect(array_column($entries, 'level'))->toBe(['warning', 'error', 'info'])
        ->and($entries[1]['context'])->toContain('#0 /app/Mail.php');

    Livewire::test(LogViewer::class, ['file' => $this->file])->assertOk()
        ->assertSee('SMTP gagal')->assertDontSee('<script>x</script>', false);
});

it('filter level & cari', function () {
    Livewire::test(LogViewer::class, ['file' => $this->file])
        ->set('level', 'error')->assertSee('SMTP gagal')->assertDontSee('Stok menipis')
        ->set('level', '')->set('search', 'stok')->assertSee('Stok menipis')->assertDontSee('SMTP gagal');
});

it('nama file di luar daftar (path traversal) diabaikan', function () {
    expect(LogReader::path('../../.env'))->toBeNull()
        ->and(LogReader::path($this->file))->toBe($this->path);

    Livewire::test(LogViewer::class, ['file' => '../../.env'])->assertSet('file', $this->file);
});

it('kosongkan file dengan konfirmasi kata sandi', function () {
    Livewire::test(LogViewer::class, ['file' => $this->file])
        ->callAction('clear', ['current_password' => 'Rahasia-Admin-1!']);

    expect(filesize($this->path))->toBe(0);
});
