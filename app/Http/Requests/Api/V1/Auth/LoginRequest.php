<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            // Satu token per device: login ulang di device yang sama mencabut token lama
            'device_uid' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
