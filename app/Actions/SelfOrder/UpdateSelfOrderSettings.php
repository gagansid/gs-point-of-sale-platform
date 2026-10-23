<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Setelan self-order satu outlet (ADR 0014, Q69; metode bayar di HP & selesai otomatis ADR 0027; izin table.manage). */
final class UpdateSelfOrderSettings
{
    /**
     * @param  array<string, mixed>  $data  enabled, payment_timeout_minutes, opens_at, closes_at, allow_pay_at_cashier, allow_qris,
     *                                      allow_points, auto_complete_minutes, welcome_text
     *
     * @throws ValidationException
     */
    public function handle(Outlet $outlet, array $data): SelfOrderSetting
    {
        $validated = Validator::make($data, self::rules(), [], [
            'payment_timeout_minutes' => 'batas waktu bayar', 'auto_complete_minutes' => 'selesai otomatis', 'opens_at' => 'jam buka', 'closes_at' => 'jam tutup', 'welcome_text' => 'teks sambutan',
        ])->validate();

        $setting = SelfOrderSetting::forOutlet($outlet->id);
        $setting->fill([
            'enabled' => (bool) $validated['enabled'],
            'payment_timeout_minutes' => (int) ($validated['payment_timeout_minutes'] ?? 15),
            'opens_at' => filled($validated['opens_at'] ?? null) ? substr((string) $validated['opens_at'], 0, 5).':00' : null,
            'closes_at' => filled($validated['closes_at'] ?? null) ? substr((string) $validated['closes_at'], 0, 5).':00' : null,
            'allow_pay_at_cashier' => (bool) ($validated['allow_pay_at_cashier'] ?? $setting->allow_pay_at_cashier),
            'allow_qris' => (bool) ($validated['allow_qris'] ?? $setting->allow_qris),
            'allow_points' => (bool) ($validated['allow_points'] ?? $setting->allow_points),
            'auto_complete_minutes' => (int) ($validated['auto_complete_minutes'] ?? $setting->auto_complete_minutes),
            'welcome_text' => filled($validated['welcome_text'] ?? null) ? trim((string) $validated['welcome_text']) : null,
        ])->save();

        return $setting;
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'payment_timeout_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'opens_at' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:closes_at'],
            'closes_at' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:opens_at', 'different:opens_at'],
            'allow_pay_at_cashier' => ['sometimes', 'boolean'],
            'allow_qris' => ['sometimes', 'boolean'],
            'allow_points' => ['sometimes', 'boolean'],
            // ADR 0027: pesanan lunas yang tidak ditandai Diantar ditutup otomatis setelah ini
            'auto_complete_minutes' => ['nullable', 'integer', 'min:30', 'max:240'],
            'welcome_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
