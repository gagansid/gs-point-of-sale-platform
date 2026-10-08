<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Services\Order\CalculationResult;
use App\Services\Order\CalculatorLine;
use App\Services\Order\OrderCalculator;

function calc(array $lines, ?DiscountType $type = null, string $value = '0', string $service = '0', string $tax = '0', bool $inclusive = false, int $rounding = 0): CalculationResult
{
    return (new OrderCalculator)->calculate($lines, $type, $value, $service, $tax, $inclusive, $rounding);
}

it('menghitung harga item = (harga + opsi) × qty − diskon item', function () {
    // (22.000 + 5.000) × 2 = 54.000 − 4.000 = 50.000
    $r = calc([new CalculatorLine('22000.00', '5000.00', 2, '4000.00')]);

    expect($r->lines[0])->toBe(['gross' => '54000.00', 'discount' => '4000.00', 'line_total' => '50000.00'])
        ->and($r->subtotal)->toBe('50000.00')
        ->and($r->grandTotal)->toBe('50000.00');
});

it('mengikuti urutan SPEC: diskon order → service → pajak exclusive → pembulatan', function () {
    // subtotal 70.000 − diskon 5.000 = dasar 65.000
    // service 5% = 3.250 → 68.250; pajak 11% × 68.250 = 7.507,50 → 75.757,50
    // dibulatkan ke 100 = 75.800 → pembulatan +42,50
    $r = calc(
        [new CalculatorLine('22000.00', '0.00', 2), new CalculatorLine('26000.00', '0.00', 1)],
        DiscountType::Fixed, '5000', service: '5', tax: '11', rounding: 100,
    );

    expect([$r->subtotal, $r->orderDiscount, $r->serviceTotal, $r->taxTotal, $r->rounding, $r->grandTotal])
        ->toBe(['70000.00', '5000.00', '3250.00', '7507.50', '42.50', '75800.00']);
});

it('pajak inclusive diekstrak dari harga, tidak menambah total', function () {
    // 111.000 sudah termasuk pajak 11%: pajak = 111.000 × 11 / 111 = 11.000
    $r = calc([new CalculatorLine('111000.00', '0.00', 1)], tax: '11', inclusive: true);

    expect($r->taxTotal)->toBe('11000.00')->and($r->grandTotal)->toBe('111000.00');
});

it('pajak inclusive dengan service charge', function () {
    // dasar 100.000 + service 10% = 110.000 (sudah termasuk pajak 10%): pajak = 110.000 × 10/110 = 10.000
    $r = calc([new CalculatorLine('100000.00', '0.00', 1)], service: '10', tax: '10', inclusive: true);

    expect([$r->serviceTotal, $r->taxTotal, $r->grandTotal])->toBe(['10000.00', '10000.00', '110000.00']);
});

it('diskon persen dihitung dari subtotal setelah diskon item', function () {
    // gross 100.000 − diskon item 10.000 = 90.000; diskon 10% = 9.000 → 81.000
    $r = calc([new CalculatorLine('50000.00', '0.00', 2, '10000.00')], DiscountType::Percent, '10');

    expect([$r->orderDiscount, $r->discountTotal, $r->grandTotal])->toBe(['9000.00', '19000.00', '81000.00'])
        ->and($r->discountPercent())->toBe('19.00');
});

it('pembulatan half-up ke kelipatan', function (string $price, int $rounding, string $grand, string $roundingAmount) {
    $r = calc([new CalculatorLine($price, '0.00', 1)], rounding: $rounding);

    expect([$r->grandTotal, $r->rounding])->toBe([$grand, $roundingAmount]);
})->with([
    'tepat 50 naik' => ['12350.00', 100, '12400.00', '50.00'],
    'di bawah 50 turun' => ['12349.99', 100, '12300.00', '-49.99'],
    'kelipatan 500' => ['12250.00', 500, '12500.00', '250.00'],
    'tanpa pembulatan' => ['12345.67', 0, '12345.67', '0.00'],
]);

it('diskon tidak pernah membuat nilai minus', function () {
    $r = calc([new CalculatorLine('10000.00', '0.00', 1, '15000.00')], DiscountType::Fixed, '99999', tax: '11', rounding: 100);

    expect([$r->subtotal, $r->orderDiscount, $r->taxTotal, $r->grandTotal])->toBe(['0.00', '0.00', '0.00', '0.00']);
});

it('diskon persen di atas 100 dibatasi 100', function () {
    expect(calc([new CalculatorLine('10000.00', '0.00', 1)], DiscountType::Percent, '150')->grandTotal)->toBe('0.00');
});

it('order kosong bernilai nol', function () {
    $r = calc([], service: '5', tax: '11', rounding: 100);

    expect($r->grandTotal)->toBe('0.00')->and($r->discountPercent())->toBe('0.00');
});

it('hasil identik untuk masukan sama (deterministik, tanpa float)', function () {
    $lines = [new CalculatorLine('0.10', '0.20', 3)];

    expect(calc($lines)->grandTotal)->toBe('0.90')->and(calc($lines)->grandTotal)->toBe('0.90');
});
