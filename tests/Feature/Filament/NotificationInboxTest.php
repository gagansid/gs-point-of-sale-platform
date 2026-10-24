<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Notifications as AdminNotificationsPage;
use App\Filament\Dashboard\Pages\Notifications as DashboardNotificationsPage;
use App\Livewire\Panel\NotificationInbox;
use App\Models\Admin;
use App\Models\Product;
use App\Models\User;
use App\Support\AlertInbox;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/** Kotak notifikasi lonceng /dashboard & /admin (Q139): sinkron dari peringatan, dibaca, hapus, riwayat, isolasi penerima. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array{key: string, icon: string, color: string, title: string, description: string, url: string, count: int} */
function inboxAlert(string $key, int $count, string $description = 'Keterangan'): array
{
    return ['key' => $key, 'icon' => 'heroicon-o-bell', 'color' => 'warning', 'title' => $count.' hal '.$key,
        'description' => $description, 'url' => '/products?tab=low_stock', 'count' => $count];
}

/** @return Collection<int, DatabaseNotification> */
function inboxRows(User|Admin $who)
{
    return DatabaseNotification::query()->where('notifiable_id', $who->getKey())->oldest()->get();
}

it('sinkron: peringatan baru → belum dibaca; sinkron ulang tanpa perubahan tidak menggandakan', function () {
    AlertInbox::sync($this->owner, [inboxAlert('stock', 2)]);
    AlertInbox::sync($this->owner, [inboxAlert('stock', 2)]);

    expect(inboxRows($this->owner))->toHaveCount(1)
        ->and(inboxRows($this->owner)->first()->read_at)->toBeNull()
        ->and(AlertInbox::unreadCount($this->owner))->toBe(1);
});

it('sinkron: jumlah naik → notifikasi baru, yang lama jadi riwayat; turun → isi diperbarui tanpa mengubah status baca; hilang → selesai', function () {
    AlertInbox::sync($this->owner, [inboxAlert('stock', 2)]);
    AlertInbox::markAllRead($this->owner);

    AlertInbox::sync($this->owner, [inboxAlert('stock', 5)]);
    [$old, $new] = inboxRows($this->owner)->all();
    expect($old->data['active'])->toBeFalse()->and($old->read_at)->not->toBeNull()
        ->and($new->data['title'])->toBe('5 hal stock')->and($new->read_at)->toBeNull();

    AlertInbox::markRead($this->owner, $new->id);
    AlertInbox::sync($this->owner, [inboxAlert('stock', 3, 'Sisa 3')]);
    $new->refresh();
    expect(inboxRows($this->owner))->toHaveCount(2)
        ->and($new->data['description'])->toBe('Sisa 3')->and($new->read_at)->not->toBeNull();

    AlertInbox::sync($this->owner, []);
    expect($new->refresh()->data['active'])->toBeFalse();

    // Muncul lagi setelah selesai → notifikasi baru
    AlertInbox::sync($this->owner, [inboxAlert('stock', 1)]);
    expect(inboxRows($this->owner))->toHaveCount(3)->and(AlertInbox::unreadCount($this->owner))->toBe(1);
});

it('sinkron: cakupan outlet terpisah tidak saling menutup; notifikasi > 90 hari dihapus', function () {
    AlertInbox::sync($this->owner, [inboxAlert('stock', 2)], 'outlet-a');
    AlertInbox::sync($this->owner, [], 'outlet-b');
    expect(inboxRows($this->owner)->first()->data['active'])->toBeTrue();

    Carbon::setTestNow(now()->addDays(AlertInbox::KEEP_DAYS + 1));
    AlertInbox::sync($this->owner, [], 'outlet-a');
    expect(inboxRows($this->owner))->toHaveCount(0);
});

it('lonceng owner: badge belum dibaca dari peringatan nyata, tab, tandai dibaca/belum, tandai semua', function () {
    Product::factory()->tracked(0)->create(['name' => 'Bagel']);

    $bell = Livewire::test(NotificationInbox::class, ['panel' => 'dashboard'])
        ->assertSee('produk perlu restok')->assertSee('gs-alerts-badge', false)->assertSee('Baru saja');
    $id = inboxRows($this->owner)->sole()->id;

    $bell->call('toggleRead', $id)->assertDontSee('produk perlu restok')->assertSee('Semua sudah dibaca')
        ->call('setTab', 'all')->assertSee('produk perlu restok')->assertDontSee('gs-alerts-badge', false)
        ->call('toggleRead', $id)->assertSee('gs-alerts-badge', false)
        ->call('markAllRead')->assertDontSee('gs-alerts-badge', false);

    expect(AlertInbox::unreadCount($this->owner))->toBe(0);
});

it('klik notifikasi: ditandai dibaca lalu membuka menu terkait', function () {
    AlertInbox::sync($this->owner, [inboxAlert('custom', 1)], 'all');
    $id = inboxRows($this->owner)->sole()->id;

    Livewire::test(NotificationInbox::class, ['panel' => 'dashboard'])->call('open', $id)->assertRedirect('/products?tab=low_stock');

    expect(inboxRows($this->owner)->sole()->read_at)->not->toBeNull();
});

it('hapus & bersihkan: peringatan aktif disembunyikan (tidak muncul lagi), yang selesai dihapus permanen', function () {
    Product::factory()->tracked(0)->create(['name' => 'Bagel']);
    $bell = Livewire::test(NotificationInbox::class, ['panel' => 'dashboard']);
    $active = inboxRows($this->owner)->sole();

    $bell->call('remove', $active->id)->call('setTab', 'all')->assertDontSee('produk perlu restok');
    // Render ulang tetap tidak memunculkan notifikasi baru selama jumlahnya sama
    $bell->call('$refresh')->assertDontSee('produk perlu restok');
    expect(inboxRows($this->owner))->toHaveCount(1)->and($active->refresh()->hidden_at)->not->toBeNull();

    AlertInbox::sync($this->owner, [inboxAlert('selesai', 1)], 'x');
    AlertInbox::sync($this->owner, [], 'x');
    $bell->call('clearAll');
    expect(inboxRows($this->owner)->pluck('data.key')->all())->toBe(['stock']);
});

it('isolasi: id notifikasi milik user/tenant lain tidak bisa dibaca, ditandai, atau dihapus', function () {
    $other = posSetup();
    $stranger = TenantContext::run($other->tenantId, fn () => User::factory()->owner()->create(['tenant_id' => $other->tenantId]));
    AlertInbox::sync($stranger, [inboxAlert('stock', 4)]);
    $foreign = inboxRows($stranger)->sole();

    Livewire::test(NotificationInbox::class, ['panel' => 'dashboard'])
        ->call('toggleRead', $foreign->id)->call('remove', $foreign->id)->call('open', $foreign->id)->assertNoRedirect()
        ->call('markAllRead')->call('clearAll')->call('setTab', 'all')->assertDontSee('4 hal stock');

    expect($foreign->refresh()->read_at)->toBeNull()->and($foreign->hidden_at)->toBeNull();
});

it('izin: kasir tanpa dashboard.access tidak punya lonceng & halaman notifikasi', function () {
    $cashier = User::factory()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($cashier);

    Livewire::test(NotificationInbox::class, ['panel' => 'dashboard'])->assertForbidden();
    expect(DashboardNotificationsPage::canAccess())->toBeFalse();
});

it('halaman Notifikasi owner: riwayat bernomor, tab Semua bawaan', function () {
    foreach (range(1, NotificationInbox::PAGE_SIZE + 3) as $i) {
        AlertInbox::sync($this->owner, [inboxAlert('k'.$i, 1)], 's'.$i);
    }

    $this->get(DashboardNotificationsPage::getUrl())->assertOk()->assertSee('Kotak masuk')->assertSee('Bersihkan semua');
    Livewire::test(NotificationInbox::class, ['panel' => 'dashboard', 'mode' => 'page'])
        ->assertSet('tab', 'all')->assertSee('1 hal k23')->assertDontSee('1 hal k1 ')
        ->call('nextPage')->assertSee('1 hal k1');
});

it('panel admin: lonceng & halaman untuk admin; panel dikunci sehingga admin tidak menyentuh notifikasi user', function () {
    AlertInbox::sync($this->owner, [inboxAlert('stock', 1)]);
    $ownerRow = inboxRows($this->owner)->sole();

    Filament::setCurrentPanel('admin');
    $admin = Admin::factory()->withTwoFactor()->create();
    $this->actingAs($admin, 'admin');
    AlertInbox::sync($admin, [inboxAlert('proofs', 2)]);

    $bell = Livewire::test(NotificationInbox::class, ['panel' => 'admin'])->assertSee('2 hal proofs')
        ->call('remove', $ownerRow->id)->call('markAllRead');
    expect($ownerRow->refresh()->read_at)->toBeNull()->and($ownerRow->hidden_at)->toBeNull()
        ->and(AlertInbox::unreadCount($admin))->toBe(0);

    expect(fn () => $bell->set('panel', 'dashboard'))->toThrow(Exception::class);
    $this->get(AdminNotificationsPage::getUrl())->assertOk()->assertSee('Kotak masuk');
});
