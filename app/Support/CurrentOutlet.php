<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Outlet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Outlet aktif untuk request saat ini (ADR 0010). Scoped singleton: direset setiap request & job.
 *
 * - API: outlet device tempat token dipakai (middleware SetOutletContext).
 * - Dashboard: pilihan di pemilih outlet sidebar ("Semua outlet" = null), disimpan di session (SetDashboardTenant).
 *
 * Data per outlet (order, shift, laporan) difilter ke ids(): outlet terpilih, atau semua outlet
 * yang boleh diakses user. Fail-closed: user tanpa outlet tidak melihat data apa pun.
 * get() selalu satu outlet konkret (terpilih / outlet pertama user) untuk zona waktu & stok.
 */
final class CurrentOutlet
{
    private const SESSION_KEY = 'dashboard_outlet_id';

    private ?User $user = null;

    private ?string $selectedId = null;

    private ?Outlet $resolved = null;

    /** Mengisi konteks. $outletId harus milik user; selain itu diabaikan (= semua outlet). */
    public static function set(?User $user, ?string $outletId = null): void
    {
        $instance = self::instance();
        $instance->user = $user;
        $instance->selectedId = $outletId !== null && ($user === null || $user->canAccessOutlet($outletId)) ? $outletId : null;
        $instance->resolved = null;
    }

    public static function forget(): void
    {
        self::set(null);
    }

    /**
     * Outlet yang boleh diakses pada request ini (dipakai OutletScope).
     * null = tanpa konteks outlet (command, job, panel /admin): tidak dibatasi.
     *
     * @return list<string>|null
     */
    public static function accessibleIds(): ?array
    {
        $instance = self::instance();

        if ($instance->user !== null) {
            return $instance->user->outletIds();
        }

        // Device token: hanya outlet device
        return $instance->selectedId !== null ? [$instance->selectedId] : null;
    }

    /** Pilihan outlet dashboard dari session (null = semua outlet). */
    public static function sessionChoice(): ?string
    {
        $id = session(self::SESSION_KEY);

        return is_string($id) ? $id : null;
    }

    /** Menyimpan pilihan pemilih outlet sidebar dashboard. Outlet di luar akses user ditolak (404). */
    public static function choose(User $user, ?string $outletId): void
    {
        if ($outletId !== null && ! $user->canAccessOutlet($outletId)) {
            abort(404);
        }

        session([self::SESSION_KEY => $outletId]);
        self::set($user, $outletId);
    }

    /** Outlet terpilih; null = semua outlet yang boleh diakses. */
    public static function selectedId(): ?string
    {
        return self::instance()->selectedId;
    }

    /**
     * ID outlet untuk memfilter data per outlet.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        $instance = self::instance();

        if ($instance->selectedId !== null) {
            return [$instance->selectedId];
        }

        return $instance->user?->outletIds() ?? Outlet::query()->pluck('id')->values()->all();
    }

    /**
     * Membatasi query ke ids().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column = 'outlet_id'): Builder
    {
        return $query->whereIn($query->qualifyColumn($column), self::ids());
    }

    /** Satu outlet konkret: terpilih, atau outlet pertama yang boleh diakses. */
    public static function get(): ?Outlet
    {
        $instance = self::instance();

        if ($instance->resolved !== null) {
            return $instance->resolved;
        }

        $query = Outlet::query()->orderBy('created_at');

        if ($instance->selectedId !== null) {
            $query->whereKey($instance->selectedId);
        } elseif ($instance->user !== null) {
            $query = $instance->user->accessibleOutlets();
        }

        return $instance->resolved = $query->first();
    }

    /** Untuk endpoint/menu yang butuh outlet: tanpa outlet yang boleh diakses → 404. */
    public static function getOrFail(): Outlet
    {
        return self::get() ?? abort(404);
    }

    public static function timezone(): string
    {
        return self::get()->timezone ?? (string) config('pos.default_timezone');
    }

    /**
     * Rentang tanggal lokal outlet (inklusif) → rentang UTC untuk query.
     * Menerima "2026-10-08" maupun "2026-10-08 00:00:00" (state DatePicker Filament).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function utcRange(string $fromDate, string $toDate): array
    {
        $timezone = self::timezone();

        return [
            self::localDay($fromDate, $timezone)->startOfDay()->utc(),
            self::localDay($toDate, $timezone)->endOfDay()->utc(),
        ];
    }

    private static function localDay(string $date, string $timezone): CarbonImmutable
    {
        // Hanya bagian tanggal yang dipakai; jam (bila ada) diabaikan
        return CarbonImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10), $timezone)
            ?: throw new \InvalidArgumentException("Tanggal tidak valid: {$date}");
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    private static function instance(): self
    {
        return app(self::class);
    }
}
