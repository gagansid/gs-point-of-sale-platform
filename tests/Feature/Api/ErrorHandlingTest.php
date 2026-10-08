<?php

declare(strict_types=1);

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::post('validation', fn (Request $r) => $r->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]));
        Route::get('business', fn () => throw BusinessException::of(ErrorCode::ShiftNotOpen, details: ['shift_id' => null]));
        Route::get('business-raw', fn () => throw new BusinessException('ORDER_ALREADY_CLOSED', 'Order sudah ditutup'));
        Route::get('auth', fn () => 'rahasia')->middleware('auth:sanctum');
        Route::get('forbidden', fn () => Gate::authorize('order.void'));
        Route::get('model/{id}', fn (string $id) => User::query()->findOrFail($id));
        Route::get('get-only', fn () => 'ok');
        Route::get('too-large', fn () => throw new PostTooLargeException);
        Route::get('bad-request', fn () => abort(400));
        Route::get('maintenance', fn () => abort(503));
        Route::get('crash', fn () => throw new RuntimeException('SQLSTATE[42S02] users.password_hash /var/www/app.php'));
        Route::get('throttled', fn () => 'ok')->middleware('throttle:auth');
    });
});

it('422 VALIDATION_ERROR dengan pesan Bahasa Indonesia per field', function () {
    $response = $this->postJson('/api/_test/validation', ['email' => 'bukan-email']);

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonPath('message', 'Data tidak valid')
        ->assertJsonPath('error.details.name.0', 'Nama wajib diisi')
        ->assertJsonPath('error.details.email.0', 'Email harus berupa alamat email yang valid');
});

it('error bisnis memakai kode, status, dan detail dari Action', function () {
    assertApiError($this->getJson('/api/_test/business'), 'SHIFT_NOT_OPEN', 409);
    $this->getJson('/api/_test/business')
        ->assertJsonPath('message', 'Shift belum dibuka')
        ->assertJsonPath('error.details', ['shift_id' => null]);

    assertApiError($this->getJson('/api/_test/business-raw'), 'ORDER_ALREADY_CLOSED', 409);
});

it('401 UNAUTHENTICATED tanpa token, termasuk tanpa header Accept', function () {
    assertApiError($this->getJson('/api/_test/auth'), 'UNAUTHENTICATED', 401);

    // Tanpa Accept JSON pun tidak boleh redirect ke halaman login
    assertApiError($this->get('/api/_test/auth'), 'UNAUTHENTICATED', 401);
});

it('401 UNAUTHENTICATED untuk token palsu', function () {
    assertApiError(
        $this->withToken('1|token-palsu')->getJson('/api/_test/auth'),
        'UNAUTHENTICATED',
        401,
    );
});

it('403 FORBIDDEN saat permission ditolak', function () {
    // Kasir tidak punya order.void (harus lewat PIN approver)
    $this->actingAs((new User)->forceFill(['id' => 1, 'role' => UserRole::Cashier]));

    assertApiError($this->getJson('/api/_test/forbidden'), 'FORBIDDEN', 403);
});

it('404 NOT_FOUND untuk data, route, dan method yang tidak ada', function () {
    assertApiError($this->getJson('/api/_test/model/'.fake()->uuid()), 'NOT_FOUND', 404);
    assertApiError($this->getJson('/api/v1/tidak-ada'), 'NOT_FOUND', 404);
    assertApiError($this->postJson('/api/_test/get-only'), 'NOT_FOUND', 404);
});

it('payload terlalu besar dan request rusak menjadi VALIDATION_ERROR', function () {
    assertApiError($this->getJson('/api/_test/too-large'), 'VALIDATION_ERROR', 422);
    assertApiError($this->getJson('/api/_test/bad-request'), 'VALIDATION_ERROR', 422);
});

it('429 TOO_MANY_REQUESTS setelah 5 percobaan login per menit, dengan Retry-After', function () {
    foreach (range(1, 5) as $_) {
        $this->getJson('/api/_test/throttled')->assertOk();
    }

    $response = $this->getJson('/api/_test/throttled');

    assertApiError($response, 'TOO_MANY_REQUESTS', 429);
    $response->assertHeader('Retry-After');
});

it('503 MAINTENANCE saat sistem diperbaiki', function () {
    assertApiError($this->getJson('/api/_test/maintenance'), 'MAINTENANCE', 503);
});

it('500 SERVER_ERROR tanpa membocorkan detail teknis walau APP_DEBUG aktif', function () {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/_test/crash');

    assertApiError($response, 'SERVER_ERROR', 500);
    $response->assertJsonPath('message', 'Terjadi kesalahan pada server')
        ->assertJsonPath('error.details', null)
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');

    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('/var/www');
});

it('halaman web tidak memakai envelope API', function () {
    $response = $this->get('/halaman-tidak-ada');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});
