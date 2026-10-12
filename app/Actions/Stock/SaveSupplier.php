<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Tambah/ubah supplier (ADR 0015, Q79; izin supplier.manage). Nama unik per bisnis (tidak peka huruf besar). */
final class SaveSupplier
{
    /**
     * @param  array<string, mixed>  $data  name, phone, email, address, notes
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?Supplier $supplier, array $data): Supplier
    {
        $validated = Validator::make($data, self::rules(), [
            'not_regex' => ':attribute tidak boleh diawali =, +, - atau @',
        ], ['name' => 'nama', 'phone' => 'telepon', 'address' => 'alamat', 'notes' => 'catatan'])->validate();

        $name = trim((string) $validated['name']);
        $taken = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($supplier !== null, fn ($q) => $q->whereKeyNot($supplier->id))->exists();

        if ($taken) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nama supplier sudah dipakai', ['name' => ['Nama supplier sudah dipakai']]);
        }

        $optional = fn (string $key): ?string => filled($validated[$key] ?? null) ? trim((string) $validated[$key]) : null;

        $supplier ??= new Supplier;
        $supplier->fill([
            'name' => $name,
            'phone' => $optional('phone'),
            'email' => ($email = $optional('email')) !== null ? mb_strtolower($email) : null,
            'address' => $optional('address'),
            'notes' => $optional('notes'),
        ])->save();

        return $supplier;
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'not_regex:/^[=+\-@]/'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s().]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
