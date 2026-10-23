<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DiningTable;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Konteks halaman pesan dari meja (ADR 0014) untuk satu request: meja dari token QR, outlet, bisnis, setelan.
 * Diisi middleware SetSelfOrderTable (halaman) atau komponen Livewire (setiap request update) sebelum data dibaca.
 */
final class SelfOrderContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Outlet $outlet,
        public readonly DiningTable $table,
        public readonly SelfOrderSetting $setting,
    ) {}

    /**
     * Meja aktif dari token QR, bisnis aktif, outlet aktif; null = QR tidak valid. Mengisi tenant context.
     * Satu-satunya pencarian lintas tenant: token acak 32 karakter, unik global.
     */
    public static function resolve(string $token): ?self
    {
        if (preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $table = DiningTable::allTenants()->withoutGlobalScopes()->where('qr_token', $token)->where('is_active', true)->first();
        $tenant = $table !== null ? Tenant::query()->find($table->tenant_id) : null;

        if ($table === null || $tenant === null || ! $tenant->isActive()) {
            return null;
        }

        TenantContext::set($tenant->id, readOnly: $tenant->isReadOnly());
        $outlet = Outlet::query()->where('is_active', true)->find($table->outlet_id);

        return $outlet !== null ? new self($tenant, $outlet, $table, SelfOrderSetting::forOutlet($outlet->id)) : null;
    }

    public static function set(self $context): void
    {
        app()->instance(self::class, $context);
    }

    public static function get(): self
    {
        return app()->bound(self::class) ? app(self::class) : throw new LogicException('Konteks self-order belum diisi');
    }

    /** null = bisa memesan; selain itu alasan untuk ditampilkan (dipakai halaman & SubmitSelfOrder). */
    public function unavailableReason(?CarbonImmutable $now = null): ?string
    {
        return match (true) {
            // Paket tanpa self-order (ADR 0024) diperlakukan seperti belum aktif
            ! $this->setting->enabled || ! Features::enabled('self_order', $this->tenant) => 'Pesan dari meja belum aktif di outlet ini. Silakan pesan di kasir.',
            $this->tenant->isReadOnly() => 'Pesan dari meja sedang tidak tersedia. Silakan pesan di kasir.',
            ! $this->setting->isOpenAt($now ?? CarbonImmutable::now(), $this->outlet->timezone) => 'Di luar jam layanan pesan dari meja ('
                .substr((string) $this->setting->opens_at, 0, 5).'–'.substr((string) $this->setting->closes_at, 0, 5).'). Silakan pesan di kasir.',
            default => null,
        };
    }

    /**
     * Login member wajib sebelum membayar (ADR 0027 K1). Bila program member bisnis nonaktif atau tidak termasuk paket,
     * login tidak mungkin: pelanggan tetap memesan sebagai tamu dengan nama (perilaku ADR 0014).
     */
    public function loginRequired(): bool
    {
        return MemberSetting::current()->enabled && Features::enabled('member', $this->tenant);
    }

    /** URL halaman meja ini. */
    public function url(): string
    {
        return $this->table->orderUrl();
    }

    /**
     * Member yang masuk di halaman member bisnis ini (ADR 0013) — sesi yang sama dengan gspos.id/m/{bisnis}. Dicek
     * ulang: bisnis sama, member aktif, program aktif, sidik kata sandi sesi cocok.
     */
    public function member(): ?Member
    {
        $member = MemberPortal::member()?->fresh();

        $valid = $member !== null && $member->tenant_id === $this->tenant->id && $member->isUsable()
            && MemberSetting::current()->enabled
            && hash_equals((string) session(MemberPortal::SESSION_PASSWORD), MemberPortal::passwordFingerprint($member));

        return $valid ? $member : null;
    }
}
