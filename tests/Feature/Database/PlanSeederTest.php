<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Quota;
use Database\Seeders\PlanSeeder;

/** Paket langganan bawaan (PlanSeeder): Basic, Pro, Bisnis publik + Enterprise khusus; aman diulang. */
afterEach(fn () => config(['pos.edition' => 'saas']));

it('membuat 4 paket berurutan dengan harga tahunan 10× bulanan', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::query()->orderBy('sort_order')->get();
    expect($plans->pluck('code')->all())->toBe(['basic', 'pro', 'bisnis', 'enterprise'])
        ->and($plans->where('is_public', true)->pluck('code')->all())->toBe(['basic', 'pro', 'bisnis'])
        ->and($plans->every(fn (Plan $p): bool => bccomp(bcmul($p->price_monthly, '10', 2), $p->price_yearly, 2) === 0))->toBeTrue()
        ->and($plans->firstWhere('code', 'pro')->limit('users'))->toBe(10)
        ->and($plans->firstWhere('code', 'enterprise')->limits)->toBe([]);
});

it('fitur & kuota paket hanya key dari master data; tingkat lebih tinggi tidak kehilangan fitur', function () {
    $this->seed(PlanSeeder::class);
    $features = array_keys(Feature::options());
    $quotas = array_keys(Quota::options());
    $plans = Plan::query()->orderBy('sort_order')->get()->keyBy('code');

    foreach ($plans as $plan) {
        expect(array_diff($plan->features, $features))->toBe([])
            ->and(array_diff(array_keys($plan->limits ?? []), $quotas))->toBe([]);
    }

    expect(array_diff($plans['basic']->features, $plans['pro']->features))->toBe([])
        ->and(array_diff($plans['pro']->features, $plans['bisnis']->features))->toBe([])
        ->and($plans['bisnis']->features)->toEqualCanonicalizing($features);
});

it('diulang tidak menimpa paket yang sudah diubah admin', function () {
    $this->seed(PlanSeeder::class);
    Plan::query()->where('code', 'pro')->update(['price_monthly' => '179000.00']);

    $this->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(4)
        ->and(Plan::query()->where('code', 'pro')->value('price_monthly'))->toBe('179000.00');
});

it('edisi jual putus tidak membuat paket', function () {
    config(['pos.edition' => 'self_hosted']);

    $this->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(0);
});
