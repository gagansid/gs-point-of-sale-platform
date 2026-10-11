<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\MemberData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Outlet;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import member dari Excel/CSV (ADR 0013, M7; izin member.manage), pola import produk (Q59): semua baris divalidasi
 * dulu; satu saja salah = tidak ada yang disimpan (error per nomor baris). Nomor HP yang sudah terdaftar = data
 * member itu diperbarui (kode, poin, tier tetap), selain itu member baru. Pengimpor wajib menyatakan punya
 * persetujuan pelanggan (UU PDP). Teks diawali = + - @ ditolak. Satu transaksi; tiap baris lewat SaveMember.
 */
final class ImportMembers
{
    public const MAX_ROWS = 1000;

    /** Kolom template (baris judul) */
    public const COLUMNS = ['nama', 'nomor_hp', 'email', 'tanggal_lahir', 'bulan_lahir', 'catatan'];

    public function __construct(private readonly SaveMember $saveMember) {}

    /**
     * @param  list<array<string, mixed>>  $rows  baris data (kunci = judul kolom), tanpa baris judul
     * @return array{created: int, updated: int}
     *
     * @throws BusinessException VALIDATION_ERROR dengan details "baris N" => pesan
     */
    public function handle(array $rows, bool $consent, ?Outlet $outlet = null): array
    {
        if (! $consent) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nyatakan bahwa pelanggan sudah menyetujui datanya dipakai untuk program member',
                ['consent' => ['Persetujuan pelanggan wajib']]);
        }

        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)->filter(fn (mixed $v): bool => filled($v))->isNotEmpty()));

        if ($rows === []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'File tidak berisi data member');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.self::MAX_ROWS.' baris per import');
        }

        $parsed = array_map(self::normalize(...), $rows);
        $this->validate($parsed);

        return DB::transaction(function () use ($parsed, $outlet): array {
            $created = 0;
            $updated = 0;

            foreach ($parsed as $row) {
                $existing = Member::query()->where('phone', $row['phone'])->first();

                $this->saveMember->handle($existing, MemberData::fromArray([
                    'name' => $row['nama'],
                    'phone' => (string) $row['phone'],
                    'email' => $row['email'] ?? $existing?->email,
                    'birth_day' => $row['tanggal_lahir'] ?? $existing?->birth_day,
                    'birth_month' => $row['bulan_lahir'] ?? $existing?->birth_month,
                    'notes' => $row['catatan'] ?? $existing?->notes,
                    'consent' => true,
                ]), $outlet);

                $existing === null ? $created++ : $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{nama: string|null, nomor_hp: string|null, phone: string|null, email: string|null, tanggal_lahir: int|string|null, bulan_lahir: int|string|null, catatan: string|null}
     */
    private static function normalize(array $row): array
    {
        $text = fn (string $key): ?string => filled($row[$key] ?? null) ? trim((string) $row[$key]) : null;
        $int = fn (string $key): int|string|null => filled($row[$key] ?? null)
            ? (is_numeric($row[$key]) && (float) $row[$key] == (int) $row[$key] ? (int) $row[$key] : (string) $row[$key])
            : null;
        // Excel menyimpan nomor tanpa 0 di depan sebagai angka (81234567890)
        $phone = $text('nomor_hp');

        return [
            'nama' => $text('nama'),
            'nomor_hp' => $phone,
            'phone' => $phone !== null ? Phone::normalize(preg_match('/^8\d{7,}$/', $phone) === 1 ? '0'.$phone : $phone) : null,
            'email' => ($email = $text('email')) !== null ? mb_strtolower($email) : null,
            'tanggal_lahir' => $int('tanggal_lahir'),
            'bulan_lahir' => $int('bulan_lahir'),
            'catatan' => $text('catatan'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws BusinessException
     */
    private function validate(array $rows): void
    {
        $errors = [];
        $noFormula = 'not_regex:/^[=+\-@]/';
        $seen = [];

        foreach ($rows as $i => $row) {
            // Baris 1 = judul kolom
            $line = 'baris '.($i + 2);

            $messages = Validator::make($row, [
                'nama' => ['required', 'string', 'max:100', $noFormula],
                'nomor_hp' => ['required', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:150'],
                'tanggal_lahir' => ['nullable', 'integer', 'between:1,31'],
                'bulan_lahir' => ['nullable', 'integer', 'between:1,12', 'required_with:tanggal_lahir'],
                'catatan' => ['nullable', 'string', 'max:255', $noFormula],
            ], [
                'not_regex' => ':attribute tidak boleh diawali =, +, - atau @',
            ], [
                'nama' => 'nama', 'nomor_hp' => 'nomor HP', 'email' => 'email', 'tanggal_lahir' => 'tanggal lahir',
                'bulan_lahir' => 'bulan lahir', 'catatan' => 'catatan',
            ])->errors()->all();

            if ($row['nomor_hp'] !== null && $row['phone'] === null) {
                $messages[] = 'nomor HP tidak valid (contoh 0812 3456 7890)';
            } elseif ($row['phone'] !== null) {
                if (isset($seen[$row['phone']])) {
                    $messages[] = "nomor HP {$row['nomor_hp']} dipakai lebih dari sekali di file";
                }
                $seen[$row['phone']] = true;
            }

            if (is_int($row['tanggal_lahir']) && is_int($row['bulan_lahir']) && ! checkdate($row['bulan_lahir'], $row['tanggal_lahir'], 2000)) {
                $messages[] = 'tanggal lahir tidak valid';
            }

            if ($messages !== []) {
                $errors[$line] = $messages;
            }
        }

        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, count($errors).' baris belum benar. Perbaiki lalu import ulang', $errors);
        }
    }
}
