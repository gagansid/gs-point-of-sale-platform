<?php

declare(strict_types=1);

use App\Services\Report\ReportPeriod;

it('preset menghasilkan rentang relatif hari ini', function (string $preset, string $from, string $to) {
    $period = ReportPeriod::preset($preset, '2026-03-31');

    expect([$period->from, $period->to])->toBe([$from, $to]);
})->with([
    ['today', '2026-03-31', '2026-03-31'],
    ['yesterday', '2026-03-30', '2026-03-30'],
    ['last_7_days', '2026-03-25', '2026-03-31'],
    ['last_30_days', '2026-03-02', '2026-03-31'],
    ['this_month', '2026-03-01', '2026-03-31'],
    // 31 Maret → bulan lalu = Februari penuh (tanpa overflow ke Maret)
    ['last_month', '2026-02-01', '2026-02-28'],
    ['tidak-dikenal', '2026-03-31', '2026-03-31'],
]);

it('normalisasi: urutan dibetulkan, tidak melewati hari ini, maks. 366 hari, input rusak = hari ini', function () {
    expect(ReportPeriod::normalize('2026-10-08', '2026-10-01', '2026-10-10'))->toEqual(new ReportPeriod('2026-10-01', '2026-10-08'))
        ->and(ReportPeriod::normalize('2026-10-01', '2026-12-01', '2026-10-10'))->toEqual(new ReportPeriod('2026-10-01', '2026-10-10'))
        ->and(ReportPeriod::normalize('2020-01-01', '2026-10-10', '2026-10-10'))->toEqual(new ReportPeriod('2025-10-10', '2026-10-10'))
        ->and(ReportPeriod::normalize('2026-10-05 00:00:00', 'kemarin', '2026-10-10'))->toEqual(new ReportPeriod('2026-10-05', '2026-10-10'))
        ->and(ReportPeriod::normalize(null, ['x'], '2026-10-10'))->toEqual(new ReportPeriod('2026-10-10', '2026-10-10'));
});

it('periode pembanding: sebelumnya (panjang sama) & tahun lalu', function () {
    $period = new ReportPeriod('2026-10-01', '2026-10-10');

    expect($period->days())->toBe(10)
        ->and($period->comparison('previous_period'))->toEqual(new ReportPeriod('2026-09-21', '2026-09-30'))
        ->and($period->comparison('previous_year'))->toEqual(new ReportPeriod('2025-10-01', '2025-10-10'))
        ->and($period->comparison('none'))->toBeNull()
        ->and((new ReportPeriod('2028-02-29', '2028-02-29'))->comparison('previous_year'))->toEqual(new ReportPeriod('2027-02-28', '2027-02-28'));
});

it('label tanggal Indonesia', function () {
    expect((new ReportPeriod('2026-10-10', '2026-10-10'))->label())->toBe('10 Okt 2026')
        ->and((new ReportPeriod('2026-10-01', '2026-10-31'))->label())->toBe('1 – 31 Okt 2026')
        ->and((new ReportPeriod('2026-09-11', '2026-10-10'))->label())->toBe('11 Sep – 10 Okt 2026')
        ->and((new ReportPeriod('2025-12-01', '2026-01-31'))->label())->toBe('1 Des 2025 – 31 Jan 2026');
});
