<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\ProductData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Import produk dari Excel/CSV (Q59). Semua baris divalidasi dulu; satu saja salah = tidak ada yang disimpan
 * (error per nomor baris). SKU yang sudah ada = produk itu diperbarui, selain itu produk baru (dijual di semua
 * outlet). Kategori dicari per nama (tidak peka huruf besar), dibuat bila belum ada. Stok awal hanya untuk
 * produk baru di $outlet. Teks diawali = + - @ ditolak (formula injection). Satu transaksi; tiap produk lewat
 * SaveProduct sehingga aturan & audit log sama dengan form.
 */
final class ImportProducts
{
    public const MAX_ROWS = 1000;

    /** Kolom template (baris judul) */
    public const COLUMNS = ['nama', 'kategori', 'harga', 'harga_modal', 'sku', 'barcode', 'lacak_stok', 'stok_awal', 'aktif'];

    public function __construct(
        private readonly SaveProduct $saveProduct,
        private readonly SaveCategory $saveCategory,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  baris data (kunci = judul kolom), tanpa baris judul
     * @return array{created: int, updated: int}
     *
     * @throws BusinessException VALIDATION_ERROR dengan details "baris N" => pesan
     */
    public function handle(array $rows, Outlet $outlet, User $by): array
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)->filter(fn (mixed $v): bool => filled($v))->isNotEmpty()));

        if ($rows === []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'File tidak berisi data produk');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.self::MAX_ROWS.' baris per import');
        }

        $parsed = array_map(self::normalize(...), $rows);
        $this->validate($parsed);

        return DB::transaction(function () use ($parsed, $outlet, $by): array {
            $categories = Category::query()->get(['id', 'name'])->keyBy(fn (Category $c): string => Str::lower($c->name));
            $created = 0;
            $updated = 0;

            foreach ($parsed as $row) {
                $categoryId = null;

                if ($row['kategori'] !== null) {
                    $key = Str::lower($row['kategori']);
                    $categories[$key] ??= $this->saveCategory->handle(null, $row['kategori'])['category'];
                    $categoryId = $categories[$key]->id;
                }

                $existing = $row['sku'] !== null ? self::bySku($row['sku'])->first() : null;

                $this->saveProduct->handle($existing, ProductData::fromArray([
                    'category_id' => $categoryId,
                    'name' => $row['nama'],
                    'sku' => $row['sku'],
                    'barcode' => $row['barcode'],
                    'price' => $row['harga'],
                    'cost_price' => $row['harga_modal'],
                    'track_stock' => $row['lacak_stok'] ?? $existing->track_stock ?? false,
                    'stock_qty' => $existing === null ? ($row['stok_awal'] ?? 0) : 0,
                    // Stok minimum produk lama tetap
                    'min_stock' => $existing !== null
                        ? ProductStock::query()->where('product_id', $existing->id)->where('outlet_id', $outlet->id)->value('min_stock')
                        : null,
                    'is_active' => $row['aktif'] ?? $existing->is_active ?? true,
                    'is_favorite' => $existing->is_favorite ?? false,
                    // Grup opsi produk lama tidak disentuh
                    'option_group_ids' => $existing?->optionGroups()->pluck('option_groups.id')->all() ?? [],
                ]), $outlet, $by);

                $existing === null ? $created++ : $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * Produk tenant aktif dengan SKU ini, tidak peka huruf besar (sama di MySQL & SQLite).
     *
     * @return Builder<Product>
     */
    private static function bySku(string $sku): Builder
    {
        return Product::query()->whereRaw('LOWER(sku) = ?', [Str::lower($sku)]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{nama: string|null, kategori: string|null, harga: string|null, harga_modal: string|null, sku: string|null,
     *     barcode: string|null, lacak_stok: bool|null, stok_awal: int|string|null, aktif: bool|null, _raw: array<string, mixed>}
     */
    private static function normalize(array $row): array
    {
        $text = fn (string $key): ?string => filled($row[$key] ?? null) ? trim((string) $row[$key]) : null;

        return [
            'nama' => $text('nama'),
            'kategori' => $text('kategori'),
            'harga' => self::money($row['harga'] ?? null),
            'harga_modal' => self::money($row['harga_modal'] ?? null),
            'sku' => $text('sku'),
            'barcode' => $text('barcode'),
            'lacak_stok' => self::bool($row['lacak_stok'] ?? null),
            'stok_awal' => filled($row['stok_awal'] ?? null) ? (is_numeric($row['stok_awal']) && (float) $row['stok_awal'] == (int) $row['stok_awal'] ? (int) $row['stok_awal'] : (string) $row['stok_awal']) : null,
            'aktif' => self::bool($row['aktif'] ?? null),
            '_raw' => $row,
        ];
    }

    /** "24000", 24000, "24.000", "Rp 24.000", "24.000,50" → "24000.00"; tidak valid → teks asli (ditolak validasi). */
    private static function money(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return Money::of(number_format((float) $value, 2, '.', ''));
        }

        $clean = str_replace(['Rp', 'rp', ' '], '', trim((string) $value));
        $clean = match (true) {
            str_contains($clean, '.') && str_contains($clean, ',') => str_replace(',', '.', str_replace('.', '', $clean)),
            preg_match('/^\d{1,3}(\.\d{3})+$/', $clean) === 1 => str_replace('.', '', $clean),
            str_contains($clean, ',') => str_replace(',', '.', $clean),
            default => $clean,
        };

        return preg_match('/^\d+(\.\d{1,2})?$/', $clean) === 1 ? Money::of($clean) : (string) $value;
    }

    private static function bool(mixed $value): ?bool
    {
        if (blank($value)) {
            return null;
        }

        return match (Str::lower(trim((string) $value))) {
            'ya', 'y', 'yes', '1', 'true', 'aktif' => true,
            'tidak', 't', 'no', 'n', '0', 'false', 'nonaktif' => false,
            default => null,
        };
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
        $seenSku = [];
        $seenBarcode = [];

        foreach ($rows as $i => $row) {
            // Baris 1 = judul kolom
            $line = 'baris '.($i + 2);
            $existing = $row['sku'] !== null ? self::bySku($row['sku'])->value('id') : null;

            $validator = Validator::make($row, [
                'nama' => ['required', 'string', 'max:150', $noFormula],
                'kategori' => ['nullable', 'string', 'max:100', $noFormula],
                'harga' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
                'harga_modal' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
                'sku' => ['nullable', 'string', 'max:50', $noFormula],
                'barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'],
                'stok_awal' => ['nullable', 'integer', 'min:-1000000', 'max:1000000'],
            ], [
                'not_regex' => ':attribute tidak boleh diawali =, +, - atau @',
            ], [
                'nama' => 'nama', 'kategori' => 'kategori', 'harga' => 'harga', 'harga_modal' => 'harga modal',
                'sku' => 'SKU', 'barcode' => 'barcode', 'stok_awal' => 'stok awal',
            ]);

            $messages = $validator->errors()->all();

            foreach (['lacak_stok', 'aktif'] as $flag) {
                if (filled($row['_raw'][$flag] ?? null) && $row[$flag] === null) {
                    $messages[] = str_replace('_', ' ', $flag).' harus "ya" atau "tidak"';
                }
            }

            if ($row['sku'] !== null && isset($seenSku[Str::lower($row['sku'])])) {
                $messages[] = "SKU {$row['sku']} dipakai lebih dari sekali di file";
            }

            if ($row['barcode'] !== null) {
                if (isset($seenBarcode[$row['barcode']])) {
                    $messages[] = "barcode {$row['barcode']} dipakai lebih dari sekali di file";
                } elseif (Product::query()->where('barcode', $row['barcode'])->when($existing !== null, fn ($q) => $q->whereKeyNot($existing))->exists()) {
                    $messages[] = "barcode {$row['barcode']} sudah dipakai produk lain";
                }
                $seenBarcode[$row['barcode']] = true;
            }

            if ($row['sku'] !== null) {
                $seenSku[Str::lower($row['sku'])] = true;
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
