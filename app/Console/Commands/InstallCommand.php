<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenant\CreateTenantWithOwner;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Edition;
use App\Support\TenantIdentifiers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Instalasi edisi jual putus (ADR 0009, SPEC Q33): membuat satu-satunya bisnis beserta outlet,
 * owner (email terverifikasi, kata sandi diminta tanpa ditampilkan), dan metode bayar bawaan.
 * Tanpa trial/langganan.
 */
final class InstallCommand extends Command
{
    protected $signature = 'pos:install
        {--business= : Nama bisnis}
        {--type=cafe : Jenis usaha (cafe, retail, other)}
        {--owner-name= : Nama owner}
        {--owner-email= : Email owner (login dashboard)}';

    protected $description = 'Menyiapkan edisi jual putus: membuat satu bisnis + owner (POS_EDITION=self_hosted)';

    public function handle(CreateTenantWithOwner $createTenant): int
    {
        if (! Edition::isSelfHosted()) {
            $this->error('pos:install hanya untuk edisi jual putus. Atur POS_EDITION=self_hosted di .env, lalu jalankan ulang.');
            $this->line('Edisi SaaS: tambah bisnis lewat panel admin atau daftar mandiri.');

            return self::FAILURE;
        }

        if (Tenant::query()->exists()) {
            $this->error('Bisnis sudah terpasang. Edisi jual putus hanya untuk satu bisnis.');

            return self::FAILURE;
        }

        $business = (string) ($this->option('business') ?: $this->ask('Nama bisnis'));
        $type = (string) ($this->option('type') ?: 'cafe');
        $ownerName = (string) ($this->option('owner-name') ?: $this->ask('Nama owner'));
        $ownerEmail = mb_strtolower(trim((string) ($this->option('owner-email') ?: $this->ask('Email owner (untuk login dashboard)'))));
        $password = (string) $this->secret('Kata sandi owner (min. 8 karakter, huruf & angka)');

        if ($password !== (string) $this->secret('Ulangi kata sandi')) {
            $this->error('Kata sandi tidak sama');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['business' => $business, 'type' => $type, 'owner_name' => $ownerName, 'owner_email' => $ownerEmail, 'password' => $password],
            [
                'business' => ['required', 'string', 'max:100'],
                'type' => ['required', 'in:'.implode(',', array_column(BusinessType::cases(), 'value'))],
                'owner_name' => ['required', 'string', 'max:100'],
                'owner_email' => ['required', 'email', 'max:150', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $tenant = $createTenant->handle(new CreateTenantData(
            name: $business,
            slug: TenantIdentifiers::uniqueSlug($business),
            businessType: BusinessType::from($type),
            status: TenantStatus::Active,
            subscriptionEndsAt: null,
            outletName: $business,
            outletCode: TenantIdentifiers::outletCode($business),
            outletTimezone: (string) config('pos.default_timezone'),
            ownerName: $ownerName,
            ownerEmail: $ownerEmail,
            ownerPassword: $password,
        ));

        $this->info("Bisnis {$tenant->name} siap. Owner login dengan {$ownerEmail}.");
        $this->line('Berikutnya: php artisan pos:create-admin untuk akun maintenance panel admin (2FA).');

        return self::SUCCESS;
    }
}
