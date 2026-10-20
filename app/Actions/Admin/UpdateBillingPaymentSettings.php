<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\Bank;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sistem → Pembayaran langganan (Q123): rekening gs.POS (bank dari master, maks. 5), gambar QRIS statis (disk public), petunjuk transfer
 * manual. Gambar QRIS lama dihapus saat diganti/dihapus.
 */
final class UpdateBillingPaymentSettings
{
    public const QRIS_DIRECTORY = 'billing';

    public function __construct(private readonly SystemSettings $settings) {}

    /** @param  array<string, mixed>  $data  accounts (list bank_id/number/holder), qris_image (path|null), manual_note */
    public function handle(Admin $by, array $data): void
    {
        $accounts = [];
        foreach (array_values((array) ($data['accounts'] ?? [])) as $i => $row) {
            $bankId = trim((string) ($row['bank_id'] ?? ''));
            $number = preg_replace('/[\s.-]/', '', (string) ($row['number'] ?? '')) ?? '';
            $holder = trim((string) ($row['holder'] ?? ''));
            if ($bankId === '' && $number === '' && $holder === '') {
                continue;
            }
            // Bank dari master; rekening lama dengan bank nonaktif tetap boleh disimpan ulang
            $bank = $bankId !== '' ? Bank::query()->find($bankId) : null;
            if ($bank === null || preg_match('/^\d{5,25}$/', $number) !== 1 || $holder === '' || mb_strlen($holder) > 80) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Rekening ke-'.($i + 1).' tidak valid', ["accounts.{$i}.number" => ['Bank, nomor (5–25 digit) & nama pemilik wajib diisi']]);
            }
            $accounts[] = ['bank_id' => $bank->id, 'bank' => $bank->short_name, 'number' => $number, 'holder' => $holder];
        }

        if (count($accounts) > SystemSettings::BILLING_ACCOUNTS_MAX) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.SystemSettings::BILLING_ACCOUNTS_MAX.' rekening');
        }

        $qris = filled($data['qris_image'] ?? null) ? ltrim((string) $data['qris_image'], '/') : null;
        if ($qris !== null && (! str_starts_with($qris, self::QRIS_DIRECTORY.'/') || str_contains($qris, '..'))) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Gambar QRIS tidak valid');
        }

        $note = trim((string) ($data['manual_note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Petunjuk maksimal 1000 karakter');
        }

        $oldQris = $this->settings->billingQrisImage();
        $this->settings->setBillingPayment($accounts, $qris, $note !== '' ? $note : null, $by);

        if ($oldQris !== null && $oldQris !== $qris) {
            Storage::disk('public')->delete($oldQris);
        }

        Log::info('Setelan pembayaran langganan diubah', ['accounts' => count($accounts), 'qris' => $qris !== null, 'admin_id' => $by->id]);
    }
}
