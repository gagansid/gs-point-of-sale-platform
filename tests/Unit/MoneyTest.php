<?php

declare(strict_types=1);

use App\Support\Money;

it('menormalkan nominal ke string 2 desimal', function (int|string $input, string $expected) {
    expect(Money::of($input))->toBe($expected);
})->with([
    [50000, '50000.00'],
    ['50000', '50000.00'],
    ['62000.5', '62000.50'],
    ['-5000', '-5000.00'],
    ['9999999999999.99', '9999999999999.99'],
]);

it('menolak nominal yang bukan angka', function (string $input) {
    Money::of($input);
})->with(['abc', '1e5', '10,5', ''])->throws(InvalidArgumentException::class);

it('menghitung tanpa galat float', function () {
    // 0.1 + 0.2 dengan float = 0.30000000000000004
    expect(Money::add('0.10', '0.20'))->toBe('0.30')
        ->and(Money::sub('100000.00', '0.01'))->toBe('99999.99')
        ->and(Money::mul('12500.00', 3))->toBe('37500.00')
        ->and(Money::compare('100.00', '100'))->toBe(0);
});

it('memformat Rupiah gaya Indonesia', function (string $value, string $expected) {
    expect(Money::format($value))->toBe($expected);
})->with([
    ['62000.00', 'Rp62.000'],
    ['1250500.50', 'Rp1.250.500,50'],
    ['-5000', '-Rp5.000'],
    ['0', 'Rp0'],
    ['9999999999999.99', 'Rp9.999.999.999.999,99'],
]);

it('membulatkan half-up', function (string $value, int $scale, string $expected) {
    expect(Money::round($value, $scale))->toBe($expected);
})->with([
    ['7507.505', 2, '7507.51'],
    ['7507.504', 2, '7507.50'],
    ['-0.005', 2, '-0.01'],
    ['757.5', 0, '758'],
]);
