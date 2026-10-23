<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan → Pendaftaran (ADR 0009 + rapikan admin 2026-10-19): lama trial & paket selama trial, buka/tutup daftar
 * sendiri dan pesan saat ditutup, batas pendaftar per hari, domain email yang diblokir, email tim yang diberi tahu.
 * Tenant yang sudah ada tidak berubah; setelan hanya berlaku untuk pendaftar setelahnya.
 */
final class UpdateOnboardingSettings
{
    public function __construct(private readonly SystemSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Admin $by, array $data): void
    {
        $data['signup_blocked_domains'] = array_values(array_unique(array_filter(array_map(
            fn (mixed $d): string => ltrim(mb_strtolower(trim((string) $d)), '@'),
            (array) ($data['signup_blocked_domains'] ?? []),
        ))));
        $data['signup_notify_emails'] = array_values(array_unique(array_filter(array_map(
            fn (mixed $e): string => mb_strtolower(trim((string) $e)),
            (array) ($data['signup_notify_emails'] ?? []),
        ))));

        $v = Validator::make($data, [
            'trial_days' => ['required', 'integer', 'min:'.SystemSettings::TRIAL_DAYS_MIN, 'max:'.SystemSettings::TRIAL_DAYS_MAX],
            'signup_enabled' => ['required', 'boolean'],
            // Paket publik saja: paket khusus (non-publik) selama trial bisa diperpanjang owner tanpa batas
            'trial_plan_id' => ['nullable', 'string', Rule::exists('plans', 'id')->where('is_active', true)->where('is_public', true)],
            'signup_daily_limit' => ['nullable', 'integer', 'min:0', 'max:'.SystemSettings::SIGNUP_DAILY_LIMIT_MAX],
            'signup_blocked_domains' => ['array', 'max:200'],
            // Label: huruf/angka, tanda hubung hanya di tengah; TLD huruf atau punycode (xn--…)
            'signup_blocked_domains.*' => ['string', 'max:100', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/'],
            'signup_notify_emails' => ['array', 'max:10'],
            'signup_notify_emails.*' => ['email', 'max:150'],
            'signup_closed_message' => ['nullable', 'string', 'max:500'],
        ], [
            'signup_blocked_domains.*.regex' => 'Domain :input tidak valid (contoh: mailinator.com)',
        ], [
            'trial_days' => 'lama trial', 'trial_plan_id' => 'paket selama trial', 'signup_daily_limit' => 'batas per hari',
            'signup_blocked_domains' => 'domain diblokir', 'signup_notify_emails' => 'email notifikasi',
            'signup_notify_emails.*' => 'email notifikasi', 'signup_closed_message' => 'pesan saat ditutup',
        ])->validate();

        $closed = trim((string) ($v['signup_closed_message'] ?? ''));

        DB::transaction(function () use ($by, $v, $closed): void {
            $this->settings->setOnboarding((int) $v['trial_days'], (bool) $v['signup_enabled'], $by);
            $this->settings->setOnboardingExtras(
                filled($v['trial_plan_id'] ?? null) ? (string) $v['trial_plan_id'] : null,
                (int) ($v['signup_daily_limit'] ?? 0),
                $v['signup_blocked_domains'],
                $v['signup_notify_emails'],
                $closed !== '' ? $closed : null,
                $by,
            );
        });

        Log::info('Setelan pendaftaran diubah', [
            'trial_days' => (int) $v['trial_days'],
            'signup_enabled' => (bool) $v['signup_enabled'],
            'trial_plan_id' => $v['trial_plan_id'] ?? null,
            'signup_daily_limit' => (int) ($v['signup_daily_limit'] ?? 0),
            'blocked_domains' => count($v['signup_blocked_domains']),
            'admin_id' => $by->id,
        ]);
    }
}
