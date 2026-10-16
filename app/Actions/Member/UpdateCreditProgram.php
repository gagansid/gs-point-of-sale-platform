<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PaymentCategory;
use App\Models\MemberSetting;
use App\Models\PaymentMethod;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Program member → Kasbon (ADR 0023; izin member.settings): aktif & batas bawaan. Metode bayar sistem
 * "Kasbon" dibuat saat pertama aktif dan aktif mengikuti setelan.
 */
final class UpdateCreditProgram
{
    public function handle(bool $enabled, ?string $defaultLimit): MemberSetting
    {
        return DB::transaction(function () use ($enabled, $defaultLimit): MemberSetting {
            $setting = MemberSetting::current();
            $setting->forceFill([
                'credit_enabled' => $enabled,
                'default_credit_limit' => filled($defaultLimit) && Money::isPositive(Money::of((string) $defaultLimit)) ? Money::of((string) $defaultLimit) : null,
            ])->save();

            $method = PaymentMethod::query()->where('category', PaymentCategory::Receivable)->first();
            if ($method === null && $enabled) {
                PaymentMethod::query()->create([
                    'category' => PaymentCategory::Receivable, 'name' => PaymentCategory::Receivable->getLabel(),
                    'requires_reference' => false, 'is_active' => true, 'sort_order' => 98,
                ]);
            } elseif ($method !== null && $method->is_active !== $enabled) {
                $method->forceFill(['is_active' => $enabled])->save();
            }

            return $setting;
        });
    }
}
