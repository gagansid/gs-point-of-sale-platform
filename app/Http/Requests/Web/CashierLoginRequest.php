<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/** Login kasir web (Q54): mode email (owner/manager) atau username (kode bisnis + username). */
final class CashierLoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:email,username'],
            'business' => ['required_if:mode,username', 'nullable', 'string', 'max:100'],
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['business' => 'kode bisnis', 'login' => $this->input('mode') === 'username' ? 'username' : 'email', 'password' => 'kata sandi'];
    }

    public function business(): ?string
    {
        return $this->input('mode') === 'username' ? (string) $this->input('business') : null;
    }
}
