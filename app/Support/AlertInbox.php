<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Kotak notifikasi lonceng /dashboard & /admin (Q139, hybrid). Peringatan tetap dihitung dari data (DashboardAlerts,
 * AdminAlerts); saat halaman panel dibuka, hasilnya disinkronkan ke tabel `notifications` milik penerima: peringatan
 * baru atau jumlahnya naik → notifikasi baru belum dibaca; jumlah turun → isi diperbarui tanpa mengubah status baca;
 * peringatan hilang → ditandai selesai (tetap di riwayat). Tanpa job/polling. Semua operasi dibatasi ke notifikasi
 * penerima sendiri, jadi tidak ada akses lintas user/tenant.
 */
final class AlertInbox
{
    public const TYPE = 'alert';

    /** Notifikasi lebih lama dari ini dihapus saat sinkron. */
    public const KEEP_DAYS = 90;

    /** Baris terbaru yang dibaca saat sinkron (cukup untuk semua jenis peringatan × cakupan outlet). */
    private const SYNC_WINDOW = 200;

    /**
     * @param  list<array{key: string, icon: string, color: string, title: string, description: string, url: string, count: int}>  $alerts
     * @param  string  $scope  cakupan hitungan (mis. outlet terpilih); peringatan cakupan lain tidak disentuh
     */
    public static function sync(User|Admin $notifiable, array $alerts, string $scope = ''): void
    {
        $now = now();
        self::query($notifiable)->where('created_at', '<', $now->copy()->subDays(self::KEEP_DAYS))->delete();

        // Notifikasi terbaru per kunci peringatan pada cakupan ini
        $latest = self::newestFirst(self::query($notifiable))->limit(self::SYNC_WINDOW)->get()
            ->filter(fn (DatabaseNotification $n): bool => ($n->data['scope'] ?? '') === $scope)
            ->unique(fn (DatabaseNotification $n): string => (string) ($n->data['key'] ?? ''))
            ->keyBy(fn (DatabaseNotification $n): string => (string) ($n->data['key'] ?? ''));

        foreach ($alerts as $alert) {
            $previous = $latest->get($alert['key']);
            $data = [...$alert, 'scope' => $scope, 'active' => true];

            if ($previous === null || ! ($previous->data['active'] ?? false) || $alert['count'] > (int) ($previous->data['count'] ?? 0)) {
                // Muncul lagi / bertambah: notifikasi baru; yang lama jadi riwayat (sudah tergantikan → dianggap dibaca)
                if ($previous !== null && ($previous->data['active'] ?? false)) {
                    $previous->forceFill(['data' => [...$previous->data, 'active' => false], 'read_at' => $previous->read_at ?? $now])->save();
                }
                $notifiable->notifications()->create(['id' => (string) Str::uuid7(), 'type' => self::TYPE, 'data' => $data]);
            } elseif (array_diff_assoc(self::comparable($data), self::comparable($previous->data)) !== []) {
                $previous->forceFill(['data' => $data])->save();
            }
        }

        // Peringatan yang sudah tidak ada: selesai, tetap tampil di riwayat
        $current = array_column($alerts, 'key');
        $latest->reject(fn (DatabaseNotification $n, string $key): bool => in_array($key, $current, true) || ! ($n->data['active'] ?? false))
            ->each(fn (DatabaseNotification $n) => $n->forceFill(['data' => [...$n->data, 'active' => false]])->save());
    }

    /** @return Collection<int, DatabaseNotification> */
    public static function latest(User|Admin $notifiable, bool $unreadOnly, int $limit): Collection
    {
        return self::newestFirst(self::visible($notifiable)->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at')))
            ->limit($limit)->get();
    }

    /** @return Builder<DatabaseNotification> notifikasi yang tampil (belum dihapus penerima), untuk halaman bernomor */
    public static function visible(User|Admin $notifiable): Builder
    {
        return self::query($notifiable)->whereNull('hidden_at');
    }

    public static function unreadCount(User|Admin $notifiable): int
    {
        return self::visible($notifiable)->whereNull('read_at')->count();
    }

    /** Notifikasi milik penerima; null bila bukan miliknya (id dari browser tidak dipercaya). */
    public static function find(User|Admin $notifiable, string $id): ?DatabaseNotification
    {
        return Str::isUuid($id) ? self::visible($notifiable)->whereKey($id)->first() : null;
    }

    public static function markRead(User|Admin $notifiable, string $id, bool $read = true): void
    {
        self::visible($notifiable)->whereKey($id)->update(['read_at' => $read ? now() : null]);
    }

    public static function markAllRead(User|Admin $notifiable): void
    {
        self::visible($notifiable)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * Hapus satu notifikasi. Yang peringatannya masih aktif hanya disembunyikan, agar sinkron berikutnya tidak
     * memunculkannya lagi sebagai baru; bila jumlahnya naik, notifikasi baru tetap dibuat.
     */
    public static function delete(User|Admin $notifiable, string $id): void
    {
        self::hide(self::visible($notifiable)->whereKey($id));
    }

    /** Bersihkan semua notifikasi penerima (aturan sama dengan delete()). */
    public static function clear(User|Admin $notifiable): void
    {
        self::hide(self::visible($notifiable));
    }

    /** @param  Builder<DatabaseNotification>  $query */
    private static function hide(Builder $query): void
    {
        $query->get()->each(fn (DatabaseNotification $n) => ($n->data['active'] ?? false)
            ? $n->forceFill(['hidden_at' => now(), 'read_at' => $n->read_at ?? now()])->save()
            : $n->delete());
    }

    /**
     * Terbaru dulu; id UUIDv7 (berurutan waktu) memutus seri created_at pada detik yang sama.
     *
     * @param  Builder<DatabaseNotification>  $query
     * @return Builder<DatabaseNotification>
     */
    public static function newestFirst(Builder $query): Builder
    {
        return $query->latest()->orderByDesc('id');
    }

    /** @return Builder<DatabaseNotification> */
    private static function query(User|Admin $notifiable): Builder
    {
        /** @var MorphMany<DatabaseNotification, User|Admin> $relation */
        $relation = $notifiable->notifications();

        return $relation->getQuery()->where('type', self::TYPE);
    }

    /**
     * Bidang yang ditampilkan (perubahan = isi diperbarui).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function comparable(array $data): array
    {
        return array_map('strval', array_intersect_key($data, array_flip(['title', 'description', 'url', 'color', 'icon', 'count'])));
    }
}
