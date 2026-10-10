<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sistem → Pendaftaran (ADR 0009): lama trial tenant baru & buka/tutup daftar sendiri.
 * Tenant yang sudah ada tidak berubah; trial hanya berlaku untuk tenant yang dibuat setelahnya.
 */
final class UpdateOnboardingSettings
{
    public function __construct(private readonly SystemSettings $settings) {}

    public function handle(Admin $by, int $trialDays, bool $signupEnabled): void
    {
        if ($trialDays < SystemSettings::TRIAL_DAYS_MIN || $trialDays > SystemSettings::TRIAL_DAYS_MAX) {
            throw BusinessException::of(ErrorCode::ValidationError, details: [
                'trial_days' => ['Lama trial harus '.SystemSettings::TRIAL_DAYS_MIN.'–'.SystemSettings::TRIAL_DAYS_MAX.' hari'],
            ]);
        }

        DB::transaction(fn () => $this->settings->setOnboarding($trialDays, $signupEnabled, $by));

        Log::info('Setelan pendaftaran diubah', [
            'trial_days' => $trialDays,
            'signup_enabled' => $signupEnabled,
            'admin_id' => $by->id,
        ]);
    }
}
