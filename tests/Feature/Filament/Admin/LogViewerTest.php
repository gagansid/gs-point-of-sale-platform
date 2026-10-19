<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\LogViewer;
use App\Models\Admin;
use App\Support\LogReader;
use Filament\Actions\Testing\TestAction;
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

    Livewire::test(LogViewer::class)->filterTable('file', $this->file)->assertOk()
        ->assertSee('SMTP gagal')->assertDontSee('<script>x</script>', false);
});

it('tabel: filter level (banyak), stack trace, tanggal, dan pencarian', function () {
    Livewire::test(LogViewer::class)
        ->filterTable('file', $this->file)
        ->filterTable('level', ['error'])->assertSee('SMTP gagal')->assertDontSee('Stok menipis')
        ->filterTable('level', ['error', 'warning'])->assertSee('Stok menipis')->assertDontSee('Login owner')
        ->filterTable('level', [])
        ->filterTable('has_trace', true)->assertSee('SMTP gagal')->assertDontSee('Stok menipis')
        ->filterTable('has_trace', null)
        ->filterTable('date', ['from' => '2026-10-12'])->assertDontSee('SMTP gagal')
        ->filterTable('date', ['from' => null])
        ->searchTable('stok')->assertSee('Stok menipis')->assertDontSee('SMTP gagal');
});

it('klik baris membuka detail: pesan, data JSON rapi, stack trace', function () {
    Livewire::test(LogViewer::class)
        ->filterTable('file', $this->file)
        ->mountAction(TestAction::make('detail')->table('1'))
        ->assertMountedActionModalSee('Stack trace')
        ->assertMountedActionModalSee('SMTP gagal')
        ->assertMountedActionModalSee('"exception": "..."')
        ->assertMountedActionModalSee('#0 /app/Mail.php');
});

it('nama file di luar daftar (path traversal) diabaikan', function () {
    expect(LogReader::path('../../.env'))->toBeNull()
        ->and(LogReader::path($this->file))->toBe($this->path);

    $page = Livewire::test(LogViewer::class)->set('tableFilters.file.value', '../../.env');
    expect($page->instance()->currentFile())->toBe($this->file);
});

it('kosongkan file dengan konfirmasi kata sandi', function () {
    Livewire::test(LogViewer::class)
        ->filterTable('file', $this->file)
        ->callAction('clear', ['current_password' => 'Rahasia-Admin-1!']);

    expect(filesize($this->path))->toBe(0);
});
