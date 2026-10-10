<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Actions\Auth\SendOwnerInvitation;
use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CreateTenantWithOwner
{
    public function __construct(private readonly CreateDefaultPaymentMethods $createPaymentMethods) {}

    /**
     * Membuat tenant, outlet pertama, dan owner pertama dalam satu transaksi
     * (SPEC: setiap tenant wajib punya minimal 1 owner aktif).
     */
    public function handle(CreateTenantData $data, ?Admin $by = null): Tenant
    {
        $tenant = DB::transaction(function () use ($data): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $data->name,
                'slug' => Str::slug($data->slug),
                'business_type' => $data->businessType,
                'status' => $data->status,
                'subscription_ends_at' => $data->subscriptionEndsAt,
            ]);

            TenantContext::run($tenant->id, function () use ($data): void {
                Outlet::query()->create([
                    'code' => Str::upper($data->outletCode),
                    'name' => $data->outletName,
                    'timezone' => $data->outletTimezone,
                    'tax_rate' => config('pos.outlet_defaults.tax_rate'),
                    'service_charge_rate' => config('pos.outlet_defaults.service_charge_rate'),
                    'tax_inclusive' => config('pos.outlet_defaults.tax_inclusive'),
                    'rounding' => config('pos.outlet_defaults.rounding'),
                    'discount_limits' => config('pos.outlet_defaults.discount_limits'),
                ]);

                $owner = new User([
                    // Owner berlaku untuk seluruh outlet tenant
                    'outlet_id' => null,
                    'name' => $data->ownerName,
                    'email' => Str::lower($data->ownerEmail),
                    // Tanpa kata sandi: acak & tidak diketahui siapa pun sampai owner mengaturnya lewat undangan
                    'password' => $data->ownerPassword ?? Str::password(48),
                    'role' => UserRole::Owner,
                    'is_active' => true,
                ]);
                // Diundang: email terverifikasi saat owner membuka link undangan
                $owner->forceFill(['email_verified_at' => $data->ownerEmailVerified && $data->ownerPassword !== null ? now() : null])->save();

                $this->createPaymentMethods->handle();
            });

            return $tenant;
        });

        Log::info('Tenant dibuat', ['tenant_id' => $tenant->id, 'admin_id' => $by?->id]);

        if ($data->ownerPassword === null) {
            $owner = User::allTenants()->where('tenant_id', $tenant->id)->where('role', UserRole::Owner)->firstOrFail();
            // Gagal kirim email tidak membatalkan tenant: owner bisa memakai "Lupa kata sandi"
            rescue(fn () => app(SendOwnerInvitation::class)->handle($owner, $tenant->name));
        }

        return $tenant;
    }
}
