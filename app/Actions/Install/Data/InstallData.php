<?php

declare(strict_types=1);

namespace App\Actions\Install\Data;

use App\Enums\BusinessType;

/**
 * Isian form installer (ADR 0016), disimpan di session terenkripsi sampai instal selesai.
 * Kata sandi tidak pernah ditampilkan ulang.
 */
final readonly class InstallData
{
    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: string|null}  $database
     */
    public function __construct(
        public array $database,
        public string $appName,
        public string $appUrl,
        public string $edition,
        public string $timezone,
        public ?string $appDomain,
        public ?string $adminDomain,
        public ?string $apiDomain,
        public ?string $mailHost,
        public ?int $mailPort,
        public ?string $mailUsername,
        public ?string $mailPassword,
        public ?string $mailEncryption,
        public ?string $mailFromAddress,
        public string $adminName,
        public string $adminEmail,
        public string $adminPassword,
        public ?string $businessName,
        public ?BusinessType $businessType,
        public ?string $ownerName,
        public ?string $ownerEmail,
        public ?string $ownerPassword,
        public ?string $managerName,
        public ?string $managerEmail,
        public ?string $managerPassword,
    ) {}

    /**
     * @param  array<string, mixed>  $database
     * @param  array<string, mixed>  $application
     * @param  array<string, mixed>  $accounts
     */
    public static function fromSession(array $database, array $application, array $accounts): self
    {
        $str = fn (array $a, string $k): ?string => filled($a[$k] ?? null) ? trim((string) $a[$k]) : null;
        $subdomains = (bool) ($application['use_subdomains'] ?? false);
        $business = (bool) ($accounts['create_business'] ?? false);
        $manager = $business && (bool) ($accounts['create_manager'] ?? false);

        return new self(
            database: [
                'host' => (string) $database['host'],
                'port' => (int) $database['port'],
                'database' => (string) $database['database'],
                'username' => (string) $database['username'],
                'password' => isset($database['password']) ? (string) $database['password'] : null,
            ],
            appName: (string) ($str($application, 'app_name') ?? 'gs.POS'),
            appUrl: rtrim((string) $application['app_url'], '/'),
            edition: (string) $application['edition'],
            timezone: (string) $application['timezone'],
            appDomain: $subdomains ? $str($application, 'app_domain') : null,
            adminDomain: $subdomains ? $str($application, 'admin_domain') : null,
            apiDomain: $subdomains ? $str($application, 'api_domain') : null,
            mailHost: $str($application, 'mail_host'),
            mailPort: filled($application['mail_port'] ?? null) ? (int) $application['mail_port'] : null,
            mailUsername: $str($application, 'mail_username'),
            mailPassword: isset($application['mail_password']) && $application['mail_password'] !== '' ? (string) $application['mail_password'] : null,
            mailEncryption: $str($application, 'mail_encryption'),
            mailFromAddress: $str($application, 'mail_from_address'),
            adminName: (string) $accounts['admin_name'],
            adminEmail: mb_strtolower(trim((string) $accounts['admin_email'])),
            adminPassword: (string) $accounts['admin_password'],
            businessName: $business ? $str($accounts, 'business_name') : null,
            businessType: $business ? BusinessType::from((string) ($accounts['business_type'] ?? 'cafe')) : null,
            ownerName: $business ? $str($accounts, 'owner_name') : null,
            ownerEmail: $business && filled($accounts['owner_email'] ?? null) ? mb_strtolower(trim((string) $accounts['owner_email'])) : null,
            ownerPassword: $business ? (string) ($accounts['owner_password'] ?? '') : null,
            managerName: $manager ? $str($accounts, 'manager_name') : null,
            managerEmail: $manager && filled($accounts['manager_email'] ?? null) ? mb_strtolower(trim((string) $accounts['manager_email'])) : null,
            managerPassword: $manager ? (string) ($accounts['manager_password'] ?? '') : null,
        );
    }

    public function createsBusiness(): bool
    {
        return $this->businessName !== null;
    }

    public function createsManager(): bool
    {
        return $this->managerName !== null;
    }

    public function usesSubdomains(): bool
    {
        return $this->appDomain !== null;
    }
}
