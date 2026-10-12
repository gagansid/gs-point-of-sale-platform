<?php

declare(strict_types=1);

namespace App\Actions\Install;

use App\Actions\Admin\CreateAdmin;
use App\Actions\Install\Data\InstallData;
use App\Actions\Tenant\CreateTenantWithOwner;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantIdentifiers;
use Database\Seeders\AppVersionSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Langkah instal 3 (ADR 0016): data sistem, super admin, lalu (opsional) bisnis + outlet + owner dan manager —
 * dalam satu transaksi. Owner terverifikasi (dibuat pemasang); manager menerima email verifikasi (SPEC Q46).
 */
final class CreateInitialAccounts
{
    public function __construct(
        private readonly CreateAdmin $createAdmin,
        private readonly CreateTenantWithOwner $createTenant,
        private readonly SaveEmployee $saveEmployee,
    ) {}

    /**
     * @return array{tenant: Tenant|null, warning: string|null}
     */
    public function handle(InstallData $data): array
    {
        return DB::transaction(function () use ($data): array {
            (new AppVersionSeeder)->run();

            $this->createAdmin->handle($data->adminName, $data->adminEmail, $data->adminPassword);

            if (! $data->createsBusiness()) {
                return ['tenant' => null, 'warning' => null];
            }

            $tenant = $this->createTenant->handle(new CreateTenantData(
                name: (string) $data->businessName,
                slug: TenantIdentifiers::uniqueSlug((string) $data->businessName),
                businessType: $data->businessType ?? BusinessType::Cafe,
                status: TenantStatus::Active,
                subscriptionEndsAt: null,
                outletName: (string) $data->businessName,
                outletCode: TenantIdentifiers::outletCode((string) $data->businessName),
                outletTimezone: $data->timezone,
                ownerName: (string) $data->ownerName,
                ownerEmail: (string) $data->ownerEmail,
                ownerPassword: (string) $data->ownerPassword,
            ));

            $warning = $data->createsManager() ? $this->createManager($tenant, $data) : null;

            return ['tenant' => $tenant, 'warning' => $warning];
        });
    }

    private function createManager(Tenant $tenant, InstallData $data): ?string
    {
        return TenantContext::run($tenant->id, function () use ($data): ?string {
            $owner = User::query()->where('role', UserRole::Owner)->firstOrFail();
            $outletId = (string) Outlet::query()->value('id');

            try {
                $this->saveEmployee->handle(null, new EmployeeData(
                    id: null,
                    name: (string) $data->managerName,
                    role: UserRole::Manager,
                    email: $data->managerEmail,
                    username: null,
                    password: $data->managerPassword,
                    pin: null,
                    outletIds: [$outletId],
                ), $owner);
            } catch (TransportExceptionInterface $e) {
                // Akun tetap dibuat; email verifikasi bisa dikirim ulang dari menu Karyawan
                report($e);

                return 'Email verifikasi manager gagal dikirim (periksa SMTP). Kirim ulang dari menu Karyawan.';
            }

            return null;
        });
    }
}
