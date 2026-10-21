<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Enums\BusinessType;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Support\TenantAppearance;
use App\Support\TenantPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pengaturan → Bisnis & tampilan (Q128, izin business.settings = owner): nama & jenis usaha, logo, warna utama
 * (preset atau hex bebas yang cukup gelap untuk teks putih), gaya sidebar, mode tampilan bawaan, panduan awal.
 * Kode bisnis (slug, dipakai login kasir web) tidak berubah. Logo hanya raster di folder bisnis sendiri.
 */
final class UpdateBusinessSettings
{
    public function __construct(private readonly UpdateTenantPreferences $preferences) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessException
     */
    public function handle(Tenant $tenant, array $data): Tenant
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = BusinessType::tryFrom((string) ($data['business_type'] ?? ''));
        $color = (string) ($data['color'] ?? TenantAppearance::DEFAULT_COLOR);
        $custom = strtolower(trim((string) ($data['custom_color'] ?? '')));
        $sidebar = (string) ($data['sidebar'] ?? 'colored');
        $mode = (string) ($data['theme_mode'] ?? 'light');
        $logo = filled($data['logo'] ?? null) ? ltrim((string) $data['logo'], '/') : null;

        $errors = array_filter([
            'name' => $name === '' || mb_strlen($name) > 100 ? 'Nama bisnis wajib diisi (maks. 100 karakter)' : null,
            'business_type' => $type === null ? 'Pilih jenis usaha' : null,
            'color' => $color !== TenantAppearance::CUSTOM && ! array_key_exists($color, TenantAppearance::PRESETS) ? 'Pilih warna utama' : null,
            'custom_color' => $color === TenantAppearance::CUSTOM && ! TenantAppearance::isValidHex($custom) ? 'Isi warna hex, mis. #0f766e'
                : ($color === TenantAppearance::CUSTOM && ! TenantAppearance::isReadableOnWhiteText($custom) ? 'Warna terlalu terang: teks putih di tombol tidak terbaca. Pilih warna lebih gelap' : null),
            'sidebar' => ! array_key_exists($sidebar, TenantAppearance::SIDEBARS) ? 'Pilih gaya sidebar' : null,
            'theme_mode' => ! array_key_exists($mode, TenantAppearance::THEME_MODES) ? 'Pilih mode tampilan' : null,
            'logo' => $logo !== null && (! str_starts_with($logo, self::logoDirectory($tenant).'/') || str_contains($logo, '..') || preg_match('/\.(png|jpe?g|webp)$/i', $logo) !== 1)
                ? 'Logo harus PNG, JPG, atau WEBP' : null,
        ]);
        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, (string) reset($errors), array_map(fn (string $m): array => [$m], $errors));
        }

        $oldLogo = TenantAppearance::logo($tenant);

        $updated = DB::transaction(function () use ($tenant, $name, $type, $color, $custom, $sidebar, $mode, $logo, $data): Tenant {
            $tenant->forceFill(['name' => $name, 'business_type' => $type])->save();

            return $this->preferences->handle($tenant, [
                // Nilai bawaan disimpan sebagai null agar preferensi tetap ringkas
                TenantAppearance::COLOR => $color === TenantAppearance::DEFAULT_COLOR ? null : $color,
                TenantAppearance::CUSTOM_COLOR => $color === TenantAppearance::CUSTOM ? $custom : null,
                TenantAppearance::SIDEBAR => $sidebar === 'colored' ? null : $sidebar,
                TenantAppearance::THEME_MODE => $mode === 'light' ? null : $mode,
                TenantAppearance::LOGO => $logo,
                TenantPreferences::SETUP_DISMISSED => (bool) ($data['show_setup_guide'] ?? true) ? null : true,
            ]);
        });

        if ($oldLogo !== null && $oldLogo !== $logo) {
            Storage::disk('public')->delete($oldLogo);
        }

        Log::info('Setelan bisnis & tampilan diubah', ['tenant_id' => $tenant->id, 'color' => $color, 'sidebar' => $sidebar, 'mode' => $mode]);

        return $updated;
    }

    public static function logoDirectory(Tenant $tenant): string
    {
        return 'tenants/'.$tenant->id;
    }
}
