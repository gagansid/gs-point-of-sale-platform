<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Tenant\CreateTenantWithOwner;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Enums\BusinessType;
use App\Enums\ErrorCode;
use App\Enums\SalesLeadStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\SalesLead;
use App\Models\Tenant;
use App\Support\TenantIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calon pelanggan → tenant (onboarding dibantu sales, ADR 0009, SPEC Q34): tenant + outlet + owner
 * + metode bayar lewat CreateTenantWithOwner tanpa kata sandi → owner menerima undangan
 * "atur kata sandi". Lead ditandai Berhasil dengan catatan slug tenant.
 */
final class CreateTenantFromLead
{
    public function __construct(private readonly CreateTenantWithOwner $createTenant) {}

    /**
     * @throws BusinessException
     */
    public function handle(
        SalesLead $lead,
        string $businessName,
        BusinessType $businessType,
        string $ownerName,
        string $ownerEmail,
        TenantStatus $status,
        ?CarbonImmutable $subscriptionEndsAt,
        Admin $by,
    ): Tenant {
        if ($lead->status === SalesLeadStatus::Won) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Lead ini sudah dibuatkan tenant', ['lead' => ['Lead sudah berstatus Berhasil']]);
        }

        $tenant = $this->createTenant->handle(new CreateTenantData(
            name: $businessName,
            slug: TenantIdentifiers::uniqueSlug($businessName),
            businessType: $businessType,
            status: $status,
            subscriptionEndsAt: $subscriptionEndsAt?->endOfDay(),
            outletName: $businessName,
            outletCode: TenantIdentifiers::outletCode($businessName),
            outletTimezone: (string) config('pos.default_timezone'),
            ownerName: $ownerName,
            ownerEmail: $ownerEmail,
            ownerPassword: null,
        ), $by);

        DB::transaction(fn () => $lead->fill([
            'status' => SalesLeadStatus::Won,
            'notes' => trim(($lead->notes !== null ? $lead->notes."\n" : '').'Tenant dibuat: '.$tenant->slug.' ('.now()->translatedFormat('j M Y').')'),
        ])->save());

        return $tenant;
    }
}
