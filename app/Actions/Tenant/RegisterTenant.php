<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Actions\Tenant\Data\CreateTenantData;
use App\Actions\Tenant\Data\SignupData;
use App\Enums\ErrorCode;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantSignedUp;
use App\Notifications\VerifyOwnerEmail;
use App\Support\SystemSettings;
use App\Support\TenantIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Daftar mandiri di gspos.id/register (ADR 0009, SPEC Q30–Q32): tenant trial + outlet + owner
 * (belum terverifikasi) + metode bayar bawaan lewat CreateTenantWithOwner, lalu kirim link verifikasi.
 * Setelan Sistem → Pendaftaran: batas per hari, paket selama trial, email notifikasi tim.
 */
final class RegisterTenant
{
    public function __construct(
        private readonly CreateTenantWithOwner $createTenant,
        private readonly SystemSettings $settings,
    ) {}

    /**
     * @return User owner baru
     *
     * @throws BusinessException FORBIDDEN bila pendaftaran ditutup
     */
    public function handle(SignupData $data): User
    {
        if (! $this->settings->signupEnabled()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Pendaftaran sedang ditutup. Hubungi tim sales gs.POS.');
        }

        // Batas pendaftar per hari (anti-spam, Sistem → Pendaftaran); hari = zona waktu platform
        $limit = $this->settings->signupDailyLimit();
        if ($limit > 0 && self::signupsToday() >= $limit) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Kuota pendaftaran hari ini sudah penuh. Coba lagi besok atau hubungi tim sales gs.POS.', ['email' => ['Kuota pendaftaran hari ini sudah penuh']]);
        }

        $planId = $this->settings->trialPlanId();
        $trialPlan = $planId !== null ? Plan::query()->where('is_active', true)->find($planId) : null;

        $tenant = DB::transaction(function () use ($data, $trialPlan): Tenant {
            $tenant = $this->createTenant->handle(new CreateTenantData(
                name: $data->businessName,
                slug: TenantIdentifiers::uniqueSlug($data->businessName),
                businessType: $data->businessType,
                status: TenantStatus::Trial,
                subscriptionEndsAt: CarbonImmutable::now()->addDays($this->settings->trialDays())->endOfDay(),
                outletName: $data->businessName,
                outletCode: TenantIdentifiers::outletCode($data->businessName),
                outletTimezone: (string) config('pos.default_timezone'),
                ownerName: $data->ownerName,
                ownerEmail: $data->email,
                ownerPassword: $data->password,
                ownerEmailVerified: false,
            ));

            // Paket selama trial: fitur & kuota paket berlaku sejak hari pertama (null = semua fitur)
            if ($trialPlan !== null) {
                $tenant->forceFill(['plan_id' => $trialPlan->id])->save();
            }

            return $tenant;
        });

        $owner = User::allTenants()
            ->where('tenant_id', $tenant->id)
            ->where('role', UserRole::Owner)
            ->firstOrFail();

        // Gagal kirim email tidak membatalkan pendaftaran: owner bisa minta kirim ulang dari dashboard
        rescue(fn () => $owner->notify(new VerifyOwnerEmail));

        $emails = $this->settings->signupNotifyEmails();
        if ($emails !== []) {
            rescue(fn () => Notification::route('mail', $emails)->notify(new TenantSignedUp($tenant, $owner)));
        }

        return $owner;
    }

    /** Pendaftar mandiri hari ini (tanpa pembuat = bukan dibuat admin/sales). */
    public static function signupsToday(): int
    {
        $start = CarbonImmutable::now((string) config('pos.default_timezone'))->startOfDay()->utc();

        return Tenant::query()->whereNull('created_by')->where('created_at', '>=', $start)->count();
    }
}
