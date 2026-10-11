<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hubungkan akun Midtrans owner (ADR 0014, Q72; izin payment_gateway.manage). Key kosong = key lama tetap; key baru
 * mengosongkan status terverifikasi. Aktif → metode bayar sistem "Online" dibuat/diaktifkan (Q73), nonaktif →
 * dinonaktifkan.
 */
final class UpdatePaymentGateway
{
    /**
     * @param  array<string, mixed>  $data  enabled, is_production, server_key, client_key, enabled_payments
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(array $data): PaymentGatewaySetting
    {
        $validated = Validator::make($data, [
            'enabled' => ['required', 'boolean'],
            'is_production' => ['required', 'boolean'],
            'server_key' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9\-_:]+$/'],
            'client_key' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9\-_:]+$/'],
            'enabled_payments' => ['nullable', 'array'],
            'enabled_payments.*' => [Rule::in(array_keys(PaymentGatewaySetting::PAYMENT_TYPES))],
        ], [], ['server_key' => 'Server Key', 'client_key' => 'Client Key', 'enabled_payments' => 'metode online'])->validate();

        return DB::transaction(function () use ($validated): PaymentGatewaySetting {
            $setting = PaymentGatewaySetting::current();
            $setting->fill([
                'enabled' => (bool) $validated['enabled'],
                'is_production' => (bool) $validated['is_production'],
                'enabled_payments' => array_values($validated['enabled_payments'] ?? []) ?: null,
            ]);

            foreach (['server_key', 'client_key'] as $key) {
                if (filled($validated[$key] ?? null)) {
                    $setting->forceFill([$key => trim((string) $validated[$key]), 'verified_at' => null]);
                }
            }

            if ($setting->isDirty('is_production')) {
                $setting->forceFill(['verified_at' => null]);
            }

            if ($setting->enabled && (blank($setting->server_key) || blank($setting->client_key))) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Isi Server Key dan Client Key Midtrans untuk mengaktifkan', [
                    'server_key' => ['Server Key & Client Key wajib diisi'],
                ]);
            }

            $setting->save();
            self::syncMethod($setting->enabled);

            return $setting;
        });
    }

    /** Metode bayar sistem "Online" mengikuti status gateway. */
    private static function syncMethod(bool $enabled): void
    {
        $method = PaymentMethod::query()->where('category', PaymentCategory::Online)->first();

        if ($method === null && $enabled) {
            PaymentMethod::query()->create([
                'category' => PaymentCategory::Online, 'name' => 'Online (Midtrans)',
                'requires_reference' => false, 'is_active' => true, 'sort_order' => 98,
            ]);
        } elseif ($method !== null && $method->is_active !== $enabled) {
            $method->forceFill(['is_active' => $enabled])->save();
        }
    }
}
