<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Enums\BusinessType;
use App\Models\Tenant;
use App\Support\Quotas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class UpdateTenant
{
    /**
     * Mengubah profil, masa langganan, dan batas khusus kuota tenant (ADR 0010, ADR 0025: {key: int}, kosong = ikut
     * paket). Status diubah lewat ChangeTenantStatus. Batas di bawah pemakaian tidak menonaktifkan data yang ada;
     * hanya mencegah menambah/mengaktifkan data baru.
     *
     * @param  array<string, mixed>|null  $limitOverrides
     */
    public function handle(Tenant $tenant, string $name, string $slug, BusinessType $businessType, ?CarbonImmutable $subscriptionEndsAt, ?array $limitOverrides = null): Tenant
    {
        Validator::make(['limit_overrides' => $limitOverrides], Quotas::rules('limit_overrides'))->validate();
        $overrides = Quotas::normalize($limitOverrides);

        return DB::transaction(function () use ($tenant, $name, $slug, $businessType, $subscriptionEndsAt, $overrides): Tenant {
            $tenant->update([
                'name' => $name,
                'slug' => Str::slug($slug),
                'business_type' => $businessType,
                'subscription_ends_at' => $subscriptionEndsAt?->endOfDay(),
                'limit_overrides' => $overrides === [] ? null : $overrides,
            ]);

            return $tenant;
        });
    }
}
