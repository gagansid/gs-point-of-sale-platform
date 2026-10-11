<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Actions\Cashier\ConfirmWebCashierUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Masuk ulang kasir web dari dashboard (Q55): kata sandi atau PIN 6 digit. */
final class CashierConfirmRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isPin = $this->loginMethod() === ConfirmWebCashierUser::METHOD_PIN;

        return [
            'method' => ['required', Rule::in([ConfirmWebCashierUser::METHOD_PASSWORD, ConfirmWebCashierUser::METHOD_PIN])],
            'secret' => $isPin ? ['required', 'string', 'digits:6'] : ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['method' => 'cara masuk', 'secret' => $this->loginMethod() === ConfirmWebCashierUser::METHOD_PIN ? 'PIN' : 'kata sandi'];
    }

    /** Cara masuk: "password" atau "pin" (bukan metode HTTP). */
    public function loginMethod(): string
    {
        return $this->input('method') === ConfirmWebCashierUser::METHOD_PIN
            ? ConfirmWebCashierUser::METHOD_PIN
            : ConfirmWebCashierUser::METHOD_PASSWORD;
    }
}
