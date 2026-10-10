<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Tenant aktif untuk request/job saat ini.
 *
 * Disimpan sebagai scoped singleton (direset setiap request & job queue), sehingga tenant dari
 * request sebelumnya tidak pernah "bocor" ke request berikutnya. tenant_id juga dicatat di
 * Context: ikut di setiap baris log dan dipulihkan otomatis di job queue (AppServiceProvider).
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $readOnly = false;

    public static function id(): ?string
    {
        return self::instance()->tenantId;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    /**
     * Untuk kode yang tidak boleh berjalan tanpa tenant (Action, Service).
     *
     * @throws LogicException
     */
    public static function idOrFail(): string
    {
        return self::id() ?? throw new LogicException('Tenant context belum di-set');
    }

    /**
     * @param  bool  $readOnly  trial/langganan habis (ADR 0009): simpan & hapus data tenant ditolak
     */
    public static function set(string $tenantId, bool $readOnly = false): void
    {
        self::instance()->tenantId = $tenantId;
        self::instance()->readOnly = $readOnly;
        Context::add('tenant_id', $tenantId);
    }

    public static function forget(): void
    {
        self::instance()->tenantId = null;
        self::instance()->readOnly = false;
        Context::forget('tenant_id');
    }

    /** Tenant aktif sedang hanya-baca (dipakai BelongsToTenant sebagai pengaman terakhir). */
    public static function isReadOnly(): bool
    {
        return self::instance()->tenantId !== null && self::instance()->readOnly;
    }

    /**
     * Menjalankan callback atas nama tenant tertentu lalu memulihkan tenant sebelumnya.
     * Dipakai seeder, command, dan panel /admin saat perlu bertindak untuk satu tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(string $tenantId, Closure $callback): mixed
    {
        $previous = self::id();
        self::set($tenantId);

        try {
            return $callback();
        } finally {
            $previous === null ? self::forget() : self::set($previous);
        }
    }

    private static function instance(): self
    {
        return app(self::class);
    }
}
