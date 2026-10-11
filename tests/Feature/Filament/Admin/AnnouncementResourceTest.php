<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Announcements\Pages\ManageAnnouncements;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Aplikasi → Pengumuman: admin, banner dashboard & kasir web, GET /v1/announcements. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

it('buat pengumuman; tampil di API dan banner dashboard', function () {
    Livewire::test(ManageAnnouncements::class)
        ->callAction('create', ['title' => 'Maintenance', 'body' => 'Minggu 02.00 <b>WIB</b>', 'starts_at' => now()->subMinute()->toDateTimeString(), 'ends_at' => null])
        ->assertHasNoFormErrors();

    $pos = posSetup();
    freshAuth();
    $this->withToken($pos->token)->getJson('/api/v1/announcements', apiHeaders())
        ->assertOk()->assertJsonPath('data.0.title', 'Maintenance')->assertJsonPath('data.0.ends_at', null);

    $owner = User::factory()->owner()->create(['tenant_id' => $pos->tenantId]);
    Filament::setCurrentPanel('dashboard');
    // Teks di-escape (bukan HTML)
    freshAuth();
    $this->actingAs($owner, 'web')->get('/dashboard/products')->assertOk()->assertSee('Maintenance')->assertSee('&lt;b&gt;WIB', false);
});

it('terjadwal & selesai tidak tayang; akhiri sekarang menghentikan', function () {
    Announcement::factory()->scheduled()->create(['title' => 'Nanti']);
    Announcement::factory()->expired()->create(['title' => 'Lama']);
    $live = Announcement::factory()->create(['title' => 'Sekarang']);

    expect(Announcement::current()->pluck('title')->all())->toBe(['Sekarang']);

    Livewire::test(ManageAnnouncements::class)->callAction(TestAction::make('end')->table($live));
    expect(Announcement::current())->toBeEmpty();
});

it('validasi: judul & isi wajib, selesai setelah mulai', function () {
    Livewire::test(ManageAnnouncements::class)
        ->callAction('create', ['title' => '', 'body' => '', 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString()])
        ->assertHasFormErrors(['title', 'body', 'ends_at']);
});

it('banner kasir web', function () {
    Announcement::factory()->create(['title' => 'Update harga']);
    $pos = posSetup(openShift: false);
    webCashierLogin($this, $pos, $pos->cashier);

    $this->get(route('cashier.shift'))->assertOk()->assertSee('Update harga');
});

it('API butuh token user', function () {
    freshAuth();
    $this->getJson('/api/v1/announcements', apiHeaders())->assertUnauthorized();
});
