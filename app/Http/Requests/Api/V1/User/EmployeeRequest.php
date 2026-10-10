<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\User;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\SecurePin;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /users (tambah) & PUT /users/{user} (ubah; field yang tidak dikirim memakai nilai lama).
 * Syarat kredensial per role (email/kata sandi vs PIN) diperiksa SaveEmployee.
 */
final class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'username'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => mb_strtolower(trim((string) $this->input($field)))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employee = $this->route('user');
        $isUpdate = $employee instanceof User;
        // POST ulang dengan id yang sama (idempotency) tidak boleh ditolak "sudah dipakai" oleh dirinya sendiri
        $ignoreId = $isUpdate ? $employee->id : (is_string($this->input('id')) ? $this->input('id') : null);
        $sometimes = $isUpdate ? 'sometimes' : 'required';

        return [
            'id' => [$isUpdate ? 'prohibited' : 'sometimes', 'uuid'],
            'name' => [$sometimes, 'string', 'max:100'],
            'role' => [$sometimes, Rule::enum(UserRole::class)],
            // Unik global (login email lintas tenant), termasuk akun yang dinonaktifkan/dihapus lunak
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($ignoreId)],
            // Kasir web (SPEC Q35): huruf kecil/angka/._- , unik per tenant
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->where('tenant_id', TenantContext::id())->ignore($ignoreId)],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', Password::defaults()],
            'pin' => ['sometimes', 'nullable', 'string', new SecurePin],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama', 'role' => 'role', 'email' => 'email', 'username' => 'username', 'password' => 'kata sandi', 'pin' => 'PIN'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['username.regex' => 'Username hanya huruf kecil, angka, titik, garis bawah, atau tanda hubung.'];
    }
}
