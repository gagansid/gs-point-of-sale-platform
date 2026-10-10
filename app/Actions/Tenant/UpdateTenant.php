<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Enums\BusinessType;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateTenant
{
    /**
     * Mengubah profil, masa langganan, dan batas outlet tenant (null = tanpa batas, ADR 0010).
     * Status diubah lewat ChangeTenantStatus. Batas di bawah jumlah outlet aktif tidak menonaktifkan
     * outlet yang ada; hanya mencegah menambah/mengaktifkan outlet baru.
     */
    public function handle(Tenant $tenant, string $name, string $slug, BusinessType $businessType, ?CarbonImmutable $subscriptionEndsAt, ?int $maxOutlets = null): Tenant
    {
        return DB::transaction(function () use ($tenant, $name, $slug, $businessType, $subscriptionEndsAt, $maxOutlets): Tenant {
            $tenant->update([
                'name' => $name,
                'slug' => Str::slug($slug),
                'business_type' => $businessType,
                'subscription_ends_at' => $subscriptionEndsAt?->endOfDay(),
                'max_outlets' => $maxOutlets,
            ]);

            return $tenant;
        });
    }
}
