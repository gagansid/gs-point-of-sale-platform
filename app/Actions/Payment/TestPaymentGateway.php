<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PaymentGatewaySetting;
use App\Services\Payment\MidtransClient;

/** Tes koneksi ke Midtrans dengan key owner (ADR 0014); berhasil → `verified_at` diisi. */
final class TestPaymentGateway
{
    /** @throws BusinessException */
    public function handle(): PaymentGatewaySetting
    {
        $setting = PaymentGatewaySetting::current();

        if (blank($setting->server_key)) {
            throw BusinessException::of(ErrorCode::PaymentGatewayNotConfigured, 'Simpan Server Key dulu sebelum tes koneksi');
        }

        MidtransClient::for($setting)->testConnection();
        $setting->forceFill(['verified_at' => now()])->save();

        return $setting;
    }
}
