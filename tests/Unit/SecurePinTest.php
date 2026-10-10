<?php

declare(strict_types=1);

use App\Rules\SecurePin;
use Illuminate\Support\Facades\Validator;

it('menerima PIN 6 digit yang tidak mudah ditebak', function (string $pin) {
    expect(Validator::make(['pin' => $pin], ['pin' => new SecurePin])->passes())->toBeTrue();
})->with(['481920', '102938', '135790', '112233']);

it('menolak PIN lemah atau bukan 6 digit', function (string $pin) {
    expect(Validator::make(['pin' => $pin], ['pin' => new SecurePin])->fails())->toBeTrue();
})->with(['111111', '000000', '123456', '654321', '345678', '12345', '1234567', '12a456', ' 48192']);
