<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ContactSalesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:150'],
            // 08xx / +628xx / 628xx, 9–15 digit
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{9,20}$/'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'business_type' => ['nullable', Rule::enum(BusinessType::class)],
            'message' => ['nullable', 'string', 'max:1000'],
            // Honeypot: field tersembunyi, hanya diisi bot
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'business_name' => 'nama bisnis',
            'phone' => 'nomor WhatsApp',
            'email' => 'email',
            'city' => 'kota',
            'business_type' => 'jenis usaha',
            'message' => 'pesan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.'];
    }

    public function isBot(): bool
    {
        return filled($this->input('website'));
    }
}
