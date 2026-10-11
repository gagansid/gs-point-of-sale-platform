<?php

declare(strict_types=1);

namespace App\Actions\Member\Data;

/**
 * Data member dari API, form dashboard, maupun daftar cepat kasir (field sudah tervalidasi, HP belum dinormalisasi).
 */
final readonly class MemberData
{
    public function __construct(
        public ?string $id,
        public string $name,
        public string $phone,
        public ?string $email,
        public ?int $birthDay,
        public ?int $birthMonth,
        public ?string $notes,
        // Persetujuan pemakaian data (UU PDP) — wajib saat mendaftar
        public bool $consent,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $optional = fn (string $key): ?string => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            name: trim((string) $data['name']),
            phone: (string) $data['phone'],
            email: ($email = $optional('email')) !== null ? mb_strtolower($email) : null,
            birthDay: filled($data['birth_day'] ?? null) ? (int) $data['birth_day'] : null,
            birthMonth: filled($data['birth_month'] ?? null) ? (int) $data['birth_month'] : null,
            notes: $optional('notes'),
            consent: (bool) ($data['consent'] ?? false),
        );
    }
}
