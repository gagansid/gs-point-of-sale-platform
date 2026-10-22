<?php

declare(strict_types=1);

use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\SaveOptionGroup;
use App\Exceptions\BusinessException;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\User;
use App\Support\OptionDefaults;
use App\Support\TenantContext;

/** Opsi bawaan per grup yang diatur owner (Q129): pemilihan awal, batas jumlah bawaan, kontrak API. */
beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    TenantContext::set($this->tenantId);
});

function defaultsGroup(int $min, int $max, array $options): OptionGroup
{
    $group = OptionGroup::factory()->create(['min_select' => $min, 'max_select' => $max]);
    foreach ($options as $i => [$name, $default]) {
        Option::factory()->create(['option_group_id' => $group->id, 'tenant_id' => $group->tenant_id, 'name' => $name,
            'price_delta' => '0.00', 'is_default' => $default, 'sort_order' => $i]);
    }
    $group->load(['options' => fn ($query) => $query->orderBy('sort_order')]);
    // is_available = kolom hasil query ketersediaan per outlet (bukan kolom tabel)
    foreach ($group->options as $i => $option) {
        $option->setAttribute('is_available', $options[$i][2]);
    }

    return $group;
}

it('pilihan awal: bawaan diatur owner, wajib tanpa bawaan = opsi pertama, opsional tanpa bawaan kosong', function () {
    $sugar = defaultsGroup(1, 1, [['Less', false, true], ['Normal', true, true]]);
    $size = defaultsGroup(1, 1, [['Regular', false, true], ['Large', false, true]]);
    $extra = defaultsGroup(0, 1, [['Boba', false, true]]);

    expect(OptionDefaults::for($sugar))->toBe($sugar->options->firstWhere('name', 'Normal')->id)
        ->and(OptionDefaults::for($size))->toBe($size->options->firstWhere('name', 'Regular')->id)
        ->and(OptionDefaults::for($extra))->toBe('');
});

it('pilihan awal: opsi habis dilewati; pilih-banyak memakai daftar bawaan maks. max_select', function () {
    $ice = defaultsGroup(1, 1, [['Normal', true, false], ['Less', false, true]]);
    $topping = defaultsGroup(0, 2, [['Boba', true, true], ['Jelly', true, true], ['Keju', true, true]]);

    expect(OptionDefaults::for($ice))->toBe($ice->options->firstWhere('name', 'Less')->id)
        ->and(OptionDefaults::for($topping))->toBe($topping->options->take(2)->pluck('id')->all());
});

it('SaveOptionGroup menolak bawaan melebihi maksimal pilihan dan menyimpan bawaan yang sah', function () {
    $data = fn (int $max, array $defaults): OptionGroupData => OptionGroupData::fromArray([
        'name' => 'Gula', 'min_select' => 1, 'max_select' => $max,
        'options' => [['name' => 'Normal', 'is_default' => $defaults[0]], ['name' => 'Less', 'is_default' => $defaults[1]]],
    ]);

    expect(fn () => app(SaveOptionGroup::class)->handle(null, $data(1, [true, true])))
        ->toThrow(BusinessException::class, 'Pilih satu opsi bawaan saja');

    app(SaveOptionGroup::class)->handle(null, $data(1, [false, true]));
    expect(Option::query()->pluck('is_default', 'name')->all())->toBe(['Normal' => false, 'Less' => true]);
});

it('API: is_default disimpan dan dikirim; lebih dari satu bawaan pada pilih-satu ditolak', function () {
    TenantContext::forget();
    $token = userToken(User::factory()->owner()->create(['tenant_id' => $this->tenantId]));
    $body = fn (bool $both): array => ['name' => 'Gula', 'min_select' => 1, 'max_select' => 1,
        'options' => [['name' => 'Normal', 'is_default' => true], ['name' => 'Less', 'is_default' => $both]]];

    $this->withToken($token)->postJson('/api/v1/option-groups', $body(false), apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.options.0.is_default', true)
        ->assertJsonPath('data.options.1.is_default', false);

    assertApiError($this->withToken($token)->postJson('/api/v1/option-groups', ['name' => 'Es', ...array_slice($body(true), 1)], apiHeaders()),
        'VALIDATION_ERROR', 422);
});
