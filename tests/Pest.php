<?php

declare(strict_types=1);

use App\Actions\Auth\IssueUserToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Feature test memakai database bersih per test. Helper bersama sesuai docs/standards/testing.md §4.
 */
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Header wajib setiap request API dari aplikasi Flutter.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function apiHeaders(array $extra = []): array
{
    return ['Accept' => 'application/json', 'X-App-Version' => '1.0.0', ...$extra];
}

/**
 * Semua respons gagal wajib berbentuk envelope yang sama.
 */
function assertApiError(TestResponse $response, string $code, int $status): void
{
    $response->assertStatus($status)
        ->assertJsonStructure(['success', 'message', 'error' => ['code', 'details'], 'meta' => ['request_id']])
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', $code);
}

/** Token user siap pakai (seperti hasil login). */
function userToken(User $user, ?Device $device = null): string
{
    return app(IssueUserToken::class)
        ->handle($user, 'app:test-'.$user->id, now()->addDay(), $device)
        ->plainTextToken;
}

/** Device token siap pakai (seperti hasil POST /devices). */
function deviceToken(Device $device): string
{
    return $device->createToken('device', ['device'])->plainTextToken;
}

/**
 * Melupakan user yang sudah di-resolve guard, agar request berikutnya dalam satu test
 * membaca token dari awal (seperti request HTTP baru).
 */
function freshAuth(): void
{
    app('auth')->forgetGuards();
}
