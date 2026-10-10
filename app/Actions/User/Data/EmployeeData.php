<?php

declare(strict_types=1);

namespace App\Actions\User\Data;

use App\Enums\UserRole;

/**
 * Karyawan dari API maupun form dashboard (field sudah tervalidasi).
 * password/pin null = tidak diubah (saat mengubah) atau tidak diisi (saat menambah).
 * outletIds null = penugasan outlet tidak diubah (saat menambah: outlet aktif saat ini).
 */
final readonly class EmployeeData
{
    public function __construct(
        public ?string $id,
        public string $name,
        public UserRole $role,
        public ?string $email,
        public ?string $username,
        public ?string $password,
        public ?string $pin,
        /** @var list<string>|null */
        public ?array $outletIds = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from((string) $data['role']);

        return new self(
            id: filled($data['id'] ?? null) ? (string) $data['id'] : null,
            name: trim((string) $data['name']),
            role: $role,
            email: filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null,
            username: filled($data['username'] ?? null) ? mb_strtolower(trim((string) $data['username'])) : null,
            password: filled($data['password'] ?? null) ? (string) $data['password'] : null,
            pin: filled($data['pin'] ?? null) ? (string) $data['pin'] : null,
            outletIds: is_array($data['outlet_ids'] ?? null)
                ? array_values(array_unique(array_map(strval(...), $data['outlet_ids'])))
                : null,
        );
    }
}
