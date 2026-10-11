<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Actions\Cashier\ConfirmWebCashierUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Masuk ulang kasir web dari dashboard (Q55): field "password" atau "pin" (6 digit) sesuai tab terpilih. */
final class CashierConfirmRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in([ConfirmWebCashierUser::METHOD_PASSWORD, ConfirmWebCashierUser::METHOD_PIN])],
            'password' => ['exclude_unless:method,password', 'required', 'string', 'max:255'],
            'pin' => ['exclude_unless:method,pin', 'required', 'string', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['method' => 'cara masuk', 'password' => 'kata sandi', 'pin' => 'PIN'];
    }

    /** Cara masuk: "password" atau "pin" (bukan metode HTTP). */
    public function loginMethod(): string
    {
        return $this->input('method') === ConfirmWebCashierUser::METHOD_PIN
            ? ConfirmWebCashierUser::METHOD_PIN
            : ConfirmWebCashierUser::METHOD_PASSWORD;
    }

    /** Kata sandi atau PIN sesuai cara masuk. */
    public function secret(): string
    {
        return $this->string($this->loginMethod())->toString();
    }
}
