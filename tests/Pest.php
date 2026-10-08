<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/*
 * Feature test memakai database bersih per test. Helper bersama (tenant(), actingAsRole(),
 * apiHeaders(), ...) ditambahkan di file ini sesuai docs/standards/testing.md §4.
 */
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
