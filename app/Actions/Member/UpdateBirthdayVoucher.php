<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\DiscountType;
use App\Models\MemberSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aturan voucher ulang tahun (ADR 0013, Q66; izin member.settings): nominal/persen, maks. potongan, minimal belanja.
 * Voucher dibuat jadwal harian di awal bulan lahir (IssueBirthdayVouchers); perubahan berlaku untuk voucher berikutnya.
 */
final class UpdateBirthdayVoucher
{
    /**
     * @param  array<string, mixed>  $rule  enabled, type, value, max_discount, min_spend
     *
     * @throws ValidationException
     */
    public function handle(array $rule): MemberSetting
    {
        $validated = Validator::make($rule, self::rules(($rule['type'] ?? null) === DiscountType::Percent->value), [], [
            'value' => 'nilai voucher', 'max_discount' => 'maks. potongan', 'min_spend' => 'minimal belanja',
        ])->validate();

        $setting = MemberSetting::current();
        $setting->forceFill(['birthday_voucher' => [
            'enabled' => (bool) $validated['enabled'],
            'type' => (string) $validated['type'],
            'value' => (string) $validated['value'],
            'max_discount' => filled($validated['max_discount'] ?? null) ? (string) $validated['max_discount'] : null,
            'min_spend' => (string) ($validated['min_spend'] ?? '0'),
        ]])->save();

        return $setting;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(bool $percent = false): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required_if:enabled,true', 'decimal:0,2', 'min:0', $percent ? 'max:100' : 'max:999999999.99'],
            'max_discount' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999.99'],
            'min_spend' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999.99'],
        ];
    }
}
