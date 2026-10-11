<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
 * Halaman error web bermerek gs.POS (resources/views/errors): identitas situs, pesan Bahasa Indonesia,
 * tanpa detail teknis. API tetap memakai envelope JSON.
 */
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function (): void {
        Route::get('/_test/forbidden', fn () => abort(403, 'Rahasia aturan akses internal'));
        Route::get('/_test/crash', fn () => throw new RuntimeException('SQLSTATE[42S02] rahasia tabel'));
        Route::get('/_test/expired', fn () => throw new TokenMismatchException);
        Route::get('/_test/throttled', fn () => throw new ThrottleRequestsException(headers: ['Retry-After' => 120]));
        Route::get('/_test/teapot', fn () => abort(418));
        Route::get('/_test/gateway', fn () => abort(502));
    });
});

it('halaman error memakai identitas gs.POS dan pesan Bahasa Indonesia', function (string $url, int $status, string $title) {
    $this->get($url)
        ->assertStatus($status)
        ->assertSee('gs.POS', false)
        ->assertSee($title)
        ->assertSee('Kembali ke beranda', false)
        ->assertDontSee('Laravel');
})->with([
    '404' => ['/halaman-yang-tidak-ada', 404, 'Halaman tidak ditemukan'],
    '403' => ['/_test/forbidden', 403, 'Akses ditolak'],
    '500' => ['/_test/crash', 500, 'Terjadi kesalahan'],
    '429' => ['/_test/throttled', 429, 'Terlalu banyak percobaan'],
    'fallback 4xx' => ['/_test/teapot', 418, 'Permintaan tidak dapat diproses'],
    'fallback 5xx' => ['/_test/gateway', 502, 'Layanan sedang terganggu'],
]);

it('pesan exception tidak pernah tampil', function () {
    $this->get('/_test/forbidden')->assertDontSee('Rahasia aturan akses internal');
    $this->get('/_test/crash')->assertDontSee('SQLSTATE')->assertDontSee('rahasia tabel');
});

it('500 menampilkan ID permintaan yang sama dengan header untuk dilaporkan', function () {
    $response = $this->get('/_test/crash', [AssignRequestId::HEADER => 'req-audit-12345678']);

    $response->assertStatus(500)->assertSee('req-audit-12345678');
});

it('429 menampilkan perkiraan waktu tunggu', function () {
    $this->get('/_test/throttled')->assertSee('Coba lagi dalam 2 menit.');
});

it('419: tombol muat ulang hanya ke host yang sama', function () {
    $this->get('/_test/expired', ['Referer' => 'http://localhost/dashboard/products'])
        ->assertStatus(419)
        ->assertSee('Sesi kedaluwarsa')
        ->assertSee('href="http://localhost/dashboard/products"', false);

    $this->get('/_test/expired', ['Referer' => 'https://evil.test/phish'])
        ->assertDontSee('evil.test');
});

it('503 maintenance memakai halaman bermerek', function () {
    $this->app->maintenanceMode()->activate([]);

    $this->get('/')->assertStatus(503)->assertSee('Sedang dalam pemeliharaan')->assertSee('gs.POS', false);

    $this->app->maintenanceMode()->deactivate();
});

it('API tetap JSON envelope, bukan halaman HTML', function () {
    assertApiError($this->getJson('/api/v1/tidak-ada', apiHeaders()), 'NOT_FOUND', 404);
});
