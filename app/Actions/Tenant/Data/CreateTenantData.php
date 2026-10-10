<?php

declare(strict_types=1);

namespace App\Actions\Tenant\Data;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use Carbon\CarbonImmutable;

/**
 * Data tenant baru beserta outlet & owner pertamanya.
 */
final readonly class CreateTenantData
{
    public function __construct(
        public string $name,
        public string $slug,
        public BusinessType $businessType,
        public TenantStatus $status,
        public ?CarbonImmutable $subscriptionEndsAt,
        public string $outletName,
        public string $outletCode,
        public string $outletTimezone,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
        // Dibuat tim (admin) = terpercaya; daftar mandiri = harus verifikasi email dulu (ADR 0009)
        public bool $ownerEmailVerified = true,
    ) {}

    /**
     * Dari state form Filament (field tervalidasi).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $endsAt = $data['subscription_ends_at'] ?? null;

        return new self(
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            businessType: BusinessType::from((string) ($data['business_type'] instanceof BusinessType ? $data['business_type']->value : $data['business_type'])),
            status: TenantStatus::from((string) ($data['status'] instanceof TenantStatus ? $data['status']->value : $data['status'])),
            subscriptionEndsAt: filled($endsAt) ? CarbonImmutable::parse($endsAt)->endOfDay() : null,
            outletName: (string) $data['outlet_name'],
            outletCode: (string) $data['outlet_code'],
            outletTimezone: (string) $data['outlet_timezone'],
            ownerName: (string) $data['owner_name'],
            ownerEmail: (string) $data['owner_email'],
            ownerPassword: (string) $data['owner_password'],
        );
    }
}
