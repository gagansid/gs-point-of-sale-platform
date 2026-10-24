<?php

declare(strict_types=1);

namespace App\Livewire\Panel;

use App\Filament\Admin\Pages\Notifications as AdminNotifications;
use App\Filament\Dashboard\Pages\Notifications as DashboardNotifications;
use App\Models\Admin;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\AlertInbox;
use App\Support\CurrentOutlet;
use App\Support\DashboardAlerts;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notifikasi panel /dashboard & /admin (Q139): lonceng topbar (mode `bell`) dan halaman Notifikasi (mode `page`).
 * Saat dirender, peringatan terkini disinkronkan ke tabel notifikasi (AlertInbox) — tanpa polling. Aksi: buka
 * (tandai dibaca lalu ke menu terkait), tandai dibaca/belum, tandai semua dibaca, hapus, bersihkan semua.
 */
final class NotificationInbox extends Component
{
    use WithPagination;

    /** Jumlah notifikasi di dropdown lonceng. */
    public const BELL_LIMIT = 8;

    public const PAGE_SIZE = 20;

    #[Locked]
    public string $panel = 'dashboard';

    #[Locked]
    public string $mode = 'bell';

    /** unread | all */
    public string $tab = 'unread';

    public function mount(string $panel, string $mode = 'bell'): void
    {
        $this->panel = $panel === 'admin' ? 'admin' : 'dashboard';
        $this->mode = $mode === 'page' ? 'page' : 'bell';
        $this->tab = $this->mode === 'page' ? 'all' : 'unread';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'all' ? 'all' : 'unread';
        $this->resetPage();
    }

    /** Klik notifikasi: tandai dibaca lalu buka menu terkait (URL dari data server sendiri). */
    public function open(string $id): void
    {
        $notification = AlertInbox::find($this->recipient(), $id);
        if ($notification === null) {
            return;
        }

        AlertInbox::markRead($this->recipient(), $id);
        $url = (string) ($notification->data['url'] ?? '');

        if ($url !== '') {
            $this->redirect($url);
        }
    }

    public function toggleRead(string $id): void
    {
        $notification = AlertInbox::find($this->recipient(), $id);

        if ($notification !== null) {
            AlertInbox::markRead($this->recipient(), $id, $notification->read_at === null);
        }
    }

    public function markAllRead(): void
    {
        AlertInbox::markAllRead($this->recipient());
    }

    public function remove(string $id): void
    {
        AlertInbox::delete($this->recipient(), $id);
    }

    public function clearAll(): void
    {
        AlertInbox::clear($this->recipient());
        $this->resetPage();
    }

    public function render(): View
    {
        $recipient = $this->recipient();

        if ($recipient instanceof Admin) {
            AlertInbox::sync($recipient, AdminAlerts::all());
        } else {
            // Hitungan dashboard mengikuti pemilih outlet (ADR 0010): tiap pilihan outlet punya riwayat sendiri
            AlertInbox::sync($recipient, DashboardAlerts::for($recipient), CurrentOutlet::selectedId() ?? 'all');
        }

        $unreadOnly = $this->tab === 'unread';

        return view('livewire.panel.notification-inbox', [
            'notifications' => $this->mode === 'page'
                ? $this->paginated($recipient, $unreadOnly)
                : AlertInbox::latest($recipient, $unreadOnly, self::BELL_LIMIT),
            'unread' => AlertInbox::unreadCount($recipient),
            'pageUrl' => $this->panel === 'admin' ? AdminNotifications::getUrl() : DashboardNotifications::getUrl(),
        ]);
    }

    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    private function paginated(User|Admin $recipient, bool $unreadOnly): LengthAwarePaginator
    {
        $query = AlertInbox::newestFirst(AlertInbox::visible($recipient));

        return ($unreadOnly ? $query->whereNull('read_at') : $query)->paginate(self::PAGE_SIZE);
    }

    /**
     * Penerima = pengguna yang masuk di guard panel (admin / web), ditentukan eksplisit karena request Livewire
     * berikutnya tidak selalu membawa konteks panel Filament. Panel dikunci saat mount (#[Locked]).
     */
    private function recipient(): User|Admin
    {
        if ($this->panel === 'admin') {
            $admin = auth('admin')->user();

            return $admin instanceof Admin ? $admin : abort(403);
        }

        $user = auth('web')->user();

        return $user instanceof User && $user->hasFullDashboard() ? $user : abort(403);
    }
}
