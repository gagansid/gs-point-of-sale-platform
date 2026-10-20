<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Bank;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tambah/ubah bank (Sistem → Bank). Kode unik huruf kecil; warna hex; logo hanya gambar raster di folder `banks/`
 * (SVG unggahan tidak diterima: bisa memuat skrip). Logo lama dihapus saat diganti.
 */
final class SaveBank
{
    public const LOGO_DIRECTORY = 'banks';

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessException
     */
    public function handle(?Bank $bank, array $data): Bank
    {
        $code = Str::lower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $short = trim((string) ($data['short_name'] ?? ''));
        $color = Str::lower(trim((string) ($data['color'] ?? '#2d4282')));
        $textColor = Str::lower(trim((string) ($data['text_color'] ?? '#ffffff')));
        $logo = filled($data['logo_path'] ?? null) ? ltrim((string) $data['logo_path'], '/') : null;

        $errors = array_filter([
            'code' => preg_match('/^[a-z0-9_]{2,20}$/', $code) !== 1 ? 'Kode 2–20 huruf kecil, angka, atau _' : null,
            'name' => $name === '' || mb_strlen($name) > 80 ? 'Nama bank wajib diisi (maks. 80 karakter)' : null,
            'short_name' => $short === '' || mb_strlen($short) > 12 ? 'Singkatan wajib diisi (maks. 12 karakter)' : null,
            'color' => preg_match('/^#[0-9a-f]{6}$/', $color) !== 1 ? 'Warna hex, mis. #0060af' : null,
            'text_color' => preg_match('/^#[0-9a-f]{6}$/', $textColor) !== 1 ? 'Warna hex, mis. #ffffff' : null,
            'logo_path' => $logo !== null && (! str_starts_with($logo, self::LOGO_DIRECTORY.'/') || str_contains($logo, '..') || preg_match('/\.(png|jpe?g|webp)$/i', $logo) !== 1) ? 'Logo harus PNG, JPG, atau WEBP' : null,
        ]);
        if (Bank::query()->where('code', $code)->when($bank !== null, fn ($q) => $q->whereKeyNot($bank->id))->exists()) {
            $errors['code'] = 'Kode bank sudah dipakai';
        }
        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, (string) reset($errors), array_map(fn (string $m): array => [$m], $errors));
        }

        $bank ??= new Bank;
        $oldLogo = $bank->logo_path;

        DB::transaction(function () use ($bank, $code, $name, $short, $color, $textColor, $logo, $data): void {
            $bank->forceFill([
                'code' => $code, 'name' => $name, 'short_name' => $short, 'color' => $color, 'text_color' => $textColor,
                'logo_path' => $logo, 'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => max(0, min(999, (int) ($data['sort_order'] ?? $bank->sort_order))),
            ])->save();
        });

        if ($oldLogo !== null && $oldLogo !== $logo) {
            Storage::disk('public')->delete($oldLogo);
        }

        Log::info('Bank disimpan', ['bank_id' => $bank->id, 'code' => $code]);

        return $bank;
    }
}
