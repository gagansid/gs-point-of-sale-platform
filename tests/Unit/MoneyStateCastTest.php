<?php

declare(strict_types=1);

use App\Filament\Shared\Forms\MoneyStateCast;

it('menampilkan nilai database tanpa mengalikan 100 (regresi)', function (mixed $db, ?string $shown) {
    expect((new MoneyStateCast)->set($db))->toBe($shown);
})->with([
    ['22000.00', '22000'],
    ['880.00', '880'],
    ['1250000.50', '1250000,50'],
    [null, null],
]);

it('membaca nilai form bermask menjadi nilai server', function (mixed $form, ?string $server) {
    expect((new MoneyStateCast)->get($form))->toBe($server);
})->with([
    ['22.000', '22000'],
    ['1.250.000', '1250000'],
    ['1.250.000,50', '1250000.50'],
    ['22000', '22000'],
    [22000, '22000'],
    ['22000.50', '22000.50'],
    ['', null],
]);
