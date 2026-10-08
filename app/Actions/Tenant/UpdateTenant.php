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
     * Mengubah profil & masa langganan tenant. Status diubah lewat ChangeTenantStatus.
     */
    public function handle(Tenant $tenant, string $name, string $slug, BusinessType $businessType, ?CarbonImmutable $subscriptionEndsAt): Tenant
    {
        return DB::transaction(function () use ($tenant, $name, $slug, $businessType, $subscriptionEndsAt): Tenant {
            $tenant->update([
                'name' => $name,
                'slug' => Str::slug($slug),
                'business_type' => $businessType,
                'subscription_ends_at' => $subscriptionEndsAt?->endOfDay(),
            ]);

            return $tenant;
        });
    }
}
