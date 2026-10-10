<?php

declare(strict_types=1);

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;

it('sama persis dengan daftar error code resmi di SPEC', function () {
    preg_match_all('/^\| (\d{3}) \| `([A-Z_]+)` \|/m', file_get_contents(base_path('docs/SPEC.md')), $matches);
    $spec = array_combine($matches[2], array_map('intval', $matches[1]));

    $enum = collect(ErrorCode::cases())->mapWithKeys(fn (ErrorCode $c) => [$c->value => $c->status()])->all();

    ksort($spec);
    ksort($enum);

    expect($enum)->toBe($spec)->and($enum)->toHaveCount(22);
});

it('punya pesan Bahasa Indonesia untuk setiap kode', function (ErrorCode $code) {
    expect($code->message())->not->toBeEmpty()->not->toEndWith('.');
})->with(ErrorCode::cases());

it('mengambil status dan pesan default dari ErrorCode', function () {
    $e = BusinessException::of(ErrorCode::PaymentExceedsBalance, details: ['remaining' => '12000.00']);

    expect($e->errorCode)->toBe('PAYMENT_EXCEEDS_BALANCE')
        ->and($e->status)->toBe(422)
        ->and($e->getMessage())->toBe('Pembayaran melebihi sisa tagihan')
        ->and($e->details)->toBe(['remaining' => '12000.00']);
});
