<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Addon;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Billing\InvoiceQuote;

/**
 * Tagihan tambah add-on di tengah masa aktif (ADR 0025 C2): prorata sisa masa aktif langganan berbayar. Add-on aktif
 * setelah lunas (MarkInvoicePaid). Owner: add-on aktif & publik; admin: termasuk add-on khusus. Add-on fitur 1 unit,
 * belum dimiliki dan belum termasuk paket; add-on kuota menambah unit ke yang sudah dimiliki.
 */
final class CreateAddonInvoice
{
    private const MAX_UNITS = 999;

    /** @throws BusinessException */
    public function handle(Tenant $tenant, Addon $addon, int $quantity, ?Admin $admin = null, bool $notifyOwners = false): SubscriptionInvoice
    {
        CreateInvoice::ensureSaas();

        if (! $addon->is_active || (! $addon->is_public && $admin === null)) {
            throw self::invalid('Add-on tidak tersedia');
        }

        $quantity = $addon->isQuota() ? $quantity : 1;
        if ($quantity < 1 || $quantity > self::MAX_UNITS) {
            throw self::invalid('Jumlah unit 1–'.self::MAX_UNITS);
        }

        if (! $addon->isQuota()) {
            $plan = $tenant->plan;
            if ($plan !== null && in_array($addon->feature_key, $plan->features, true)) {
                throw self::invalid("Fitur {$addon->name} sudah termasuk paket {$plan->name}");
            }
            if (TenantAddon::forTenant($tenant->id)->where('addon_id', $addon->id)->where('status', TenantAddon::ACTIVE)->exists()) {
                throw self::invalid("Add-on {$addon->name} sudah aktif");
            }
        }

        $quote = InvoiceQuote::forAddon($tenant, $addon, $quantity);
        $plan = Plan::query()->findOrFail($tenant->plan_id);

        return CreateInvoice::persist($tenant, $plan, $tenant->billing_cycle ?? throw self::invalid('Siklus langganan tidak diketahui'), $quote, $admin, $notifyOwners);
    }

    private static function invalid(string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, ['addon_id' => [$message]]);
    }
}
