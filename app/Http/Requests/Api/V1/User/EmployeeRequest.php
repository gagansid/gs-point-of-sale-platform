<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\User;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\SecurePin;
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
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employee = $this->route('user');
        $isUpdate = $employee instanceof User;
        $sometimes = $isUpdate ? 'sometimes' : 'required';

        return [
            'id' => [$isUpdate ? 'prohibited' : 'sometimes', 'uuid'],
            'name' => [$sometimes, 'string', 'max:100'],
            'role' => [$sometimes, Rule::enum(UserRole::class)],
            // Unik global (login email lintas tenant), termasuk akun yang dinonaktifkan/dihapus lunak
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($isUpdate ? $employee->id : null)],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', Password::defaults()],
            'pin' => ['sometimes', 'nullable', 'string', new SecurePin],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama', 'role' => 'role', 'email' => 'email', 'password' => 'kata sandi', 'pin' => 'PIN'];
    }
}
