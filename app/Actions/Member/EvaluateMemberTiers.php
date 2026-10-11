<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\MemberSetting;
use App\Services\Member\TierEvaluator;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evaluasi tier harian semua tenant (ADR 0013, Q64): belanja lama keluar dari jendela 12 bulan sehingga tier bisa
 * turun tanpa transaksi baru. Command `pos:evaluate-member-tiers`; satu transaksi per tenant, aman diulang.
 */
final class EvaluateMemberTiers
{
    public function __construct(private readonly TierEvaluator $evaluator) {}

    /** @return int jumlah member yang tier-nya berubah */
    public function handle(?CarbonImmutable $now = null): int
    {
        $changed = 0;

        foreach (MemberSetting::allTenants()->where('enabled', true)->pluck('tenant_id') as $tenantId) {
            $changed += TenantContext::run((string) $tenantId, fn (): int => DB::transaction(fn (): int => $this->evaluator->refreshAll($now)));
        }

        return $changed;
    }
}
