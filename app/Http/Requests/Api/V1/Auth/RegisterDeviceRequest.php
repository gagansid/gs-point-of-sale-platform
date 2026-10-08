<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AppPlatform;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('device.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'device_uid' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'platform' => ['nullable', Rule::enum(AppPlatform::class)],
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/'],
            // Outlet wajib milik tenant sendiri (kosong = outlet pertama, MVP 1 outlet)
            'outlet_id' => [
                'nullable',
                'uuid',
                Rule::exists('outlets', 'id')->where('tenant_id', TenantContext::id())->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama perangkat', 'outlet_id' => 'outlet'];
    }
}
