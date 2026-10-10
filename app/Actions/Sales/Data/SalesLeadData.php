<?php

declare(strict_types=1);

namespace App\Actions\Sales\Data;

/**
 * Isi form "Hubungi sales" (sudah tervalidasi ContactSalesRequest).
 */
final readonly class SalesLeadData
{
    public function __construct(
        public string $name,
        public string $businessName,
        public string $phone,
        public ?string $email,
        public ?string $city,
        public ?string $businessType,
        public ?string $message,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $optional = fn (string $key): ?string => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        return new self(
            name: trim((string) $data['name']),
            businessName: trim((string) $data['business_name']),
            // Simpan hanya angka & tanda + agar tautan WhatsApp/telepon konsisten
            phone: (string) preg_replace('/[^\d+]/', '', (string) $data['phone']),
            email: $optional('email'),
            city: $optional('city'),
            businessType: $optional('business_type'),
            message: $optional('message'),
        );
    }
}
