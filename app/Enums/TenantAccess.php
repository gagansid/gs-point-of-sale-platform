<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tingkat akses tenant (ADR 0009, SPEC Q32).
 */
enum TenantAccess: string
{
    /** Aktif/trial dan masa langganan belum habis. */
    case Full = 'full';

    /** Trial/langganan habis: login & lihat data boleh, perubahan ditolak SUBSCRIPTION_EXPIRED. */
    case ReadOnly = 'read_only';

    /** Ditangguhkan admin: tidak bisa login (TENANT_SUSPENDED). */
    case Blocked = 'blocked';
}
