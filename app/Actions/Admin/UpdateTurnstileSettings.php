<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Services\Security\Turnstile;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sistem → Keamanan: on/off Cloudflare Turnstile per halaman login (super admin, owner & lupa kata sandi, kasir web).
 * Mengaktifkan butuh kunci TURNSTILE_SITE_KEY & TURNSTILE_SECRET_KEY di .env; mematikan selalu boleh.
 */
final class UpdateTurnstileSettings
{
    public function __construct(private readonly SystemSettings $settings) {}

    /** @param array<string, mixed> $data halaman login (Turnstile::AUDIENCES) → aktif */
    public function handle(Admin $by, array $data): void
    {
        $audiences = [];
        foreach (array_keys(Turnstile::AUDIENCES) as $audience) {
            $audiences[$audience] = (bool) ($data[$audience] ?? false);
        }

        if (in_array(true, $audiences, true) && ! Turnstile::configured()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Isi TURNSTILE_SITE_KEY & TURNSTILE_SECRET_KEY di .env sebelum mengaktifkan Turnstile');
        }

        DB::transaction(fn () => $this->settings->setTurnstile($audiences, $by));

        Log::info('Setelan Turnstile diubah', [...$audiences, 'admin_id' => $by->id]);
    }
}
