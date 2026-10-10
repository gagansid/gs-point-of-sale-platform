<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Actions\Tenant\Data\CreateTenantData;
use App\Actions\Tenant\Data\SignupData;
use App\Enums\ErrorCode;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Support\SystemSettings;
use App\Support\TenantIdentifiers;
use Carbon\CarbonImmutable;

/**
 * Daftar mandiri di gspos.id/register (ADR 0009, SPEC Q30–Q32): tenant trial + outlet + owner
 * (belum terverifikasi) + metode bayar bawaan lewat CreateTenantWithOwner, lalu kirim link verifikasi.
 */
final class RegisterTenant
{
    public function __construct(
        private readonly CreateTenantWithOwner $createTenant,
        private readonly SystemSettings $settings,
    ) {}

    /**
     * @return User owner baru
     *
     * @throws BusinessException FORBIDDEN bila pendaftaran ditutup
     */
    public function handle(SignupData $data): User
    {
        if (! $this->settings->signupEnabled()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Pendaftaran sedang ditutup. Hubungi tim sales gs.POS.');
        }

        $tenant = $this->createTenant->handle(new CreateTenantData(
            name: $data->businessName,
            slug: TenantIdentifiers::uniqueSlug($data->businessName),
            businessType: $data->businessType,
            status: TenantStatus::Trial,
            subscriptionEndsAt: CarbonImmutable::now()->addDays($this->settings->trialDays())->endOfDay(),
            outletName: $data->businessName,
            outletCode: TenantIdentifiers::outletCode($data->businessName),
            outletTimezone: (string) config('pos.default_timezone'),
            ownerName: $data->ownerName,
            ownerEmail: $data->email,
            ownerPassword: $data->password,
            ownerEmailVerified: false,
        ));

        $owner = User::allTenants()
            ->where('tenant_id', $tenant->id)
            ->where('role', UserRole::Owner)
            ->firstOrFail();

        // Gagal kirim email tidak membatalkan pendaftaran: owner bisa minta kirim ulang dari dashboard
        rescue(fn () => $owner->notify(new VerifyOwnerEmail));

        return $owner;
    }
}
