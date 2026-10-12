<?php

declare(strict_types=1);

use App\Http\Controllers\Web\TestScenarioController;

it('menampilkan halaman test scenario QA di lingkungan local', function () {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/docs/test-scenarios')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Test Scenario Menyeluruh', false)
        ->assertSee('id="test-scenarios"', false);
});

it('menolak halaman test scenario QA di production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/docs/test-scenarios')->assertForbidden();
});

it('data skenario berupa JSON valid dengan ID unik', function () {
    $html = file_get_contents(base_path(TestScenarioController::PATH));
    preg_match('#<script id="test-scenarios" type="application/json">(.*?)</script>#s', $html, $match);

    $data = json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    $ids = collect($data['categories'])->flatMap(fn (array $c): array => array_column($c['scenarios'], 'id'));

    expect($ids)->not->toBeEmpty()
        ->and($ids->duplicates())->toBeEmpty();
});
