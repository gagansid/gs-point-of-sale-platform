<?php

declare(strict_types=1);

namespace App\Actions\Tenant\Data;

use App\Enums\BusinessType;

/**
 * Isi form daftar mandiri gspos.id/register (sudah tervalidasi SignupRequest).
 */
final readonly class SignupData
{
    public function __construct(
        public string $businessName,
        public BusinessType $businessType,
        public string $ownerName,
        public string $email,
        public string $password,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            businessName: trim((string) $data['business_name']),
            businessType: BusinessType::from((string) $data['business_type']),
            ownerName: trim((string) $data['name']),
            email: mb_strtolower(trim((string) $data['email'])),
            password: (string) $data['password'],
        );
    }
}
