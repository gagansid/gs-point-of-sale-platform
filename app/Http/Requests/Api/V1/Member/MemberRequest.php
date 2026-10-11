<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Member;

use Illuminate\Foundation\Http\FormRequest;

/** Daftar member (ADR 0013, Q61). Nomor HP dinormalisasi & dicek unik di SaveMember. */
final class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('member.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            ...self::fieldRules(),
            'consent' => ['accepted'],
        ];
    }

    /**
     * Aturan bersama API & form dashboard.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'required_with:birth_day', 'integer', 'between:1,12'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama', 'phone' => 'nomor HP', 'birth_day' => 'tanggal lahir', 'birth_month' => 'bulan lahir',
            'notes' => 'catatan', 'consent' => 'persetujuan data',
        ];
    }
}
