<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;

it('mengubah tanggal lokal outlet menjadi rentang UTC, termasuk format DatePicker Filament', function (string $from, string $to) {
    $outlet = Outlet::factory()->create(['timezone' => 'Asia/Jakarta']);
    TenantContext::set($outlet->tenant_id);

    [$start, $end] = CurrentOutlet::utcRange($from, $to);

    expect($start->toDateTimeString())->toBe('2026-10-07 17:00:00')
        ->and($end->toDateTimeString())->toBe('2026-10-08 16:59:59');
})->with([
    'Y-m-d' => ['2026-10-08', '2026-10-08'],
    'regresi: state DatePicker' => ['2026-10-08 00:00:00', '2026-10-08 00:00:00'],
]);

it('menolak tanggal yang tidak valid', function () {
    CurrentOutlet::utcRange('kemarin', '2026-10-08');
})->throws(InvalidArgumentException::class);
