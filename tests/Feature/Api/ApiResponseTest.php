<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::post('created', fn () => ApiResponse::success(
            ['id' => 'abc', 'grand_total' => '62000.00', 'table_label' => null],
            'Transaksi berhasil disimpan',
            201,
            ['idempotent_replay' => false],
        ));

        Route::get('list', fn () => ApiResponse::paginated(
            new LengthAwarePaginator([['id' => 'a'], ['id' => 'b']], 135, 20, 1),
            JsonResource::class,
        ));
    });
});

it('membungkus data sukses dalam envelope standar', function () {
    $response = $this->postJson('/api/_test/created');

    $response->assertCreated()->assertExactJson([
        'success' => true,
        'message' => 'Transaksi berhasil disimpan',
        'data' => ['id' => 'abc', 'grand_total' => '62000.00', 'table_label' => null],
        'meta' => [
            'request_id' => $response->headers->get('X-Request-Id'),
            'idempotent_replay' => false,
        ],
    ]);
});

it('menyertakan meta pagination pada daftar', function () {
    $this->getJson('/api/_test/list')
        ->assertOk()
        ->assertJsonPath('data', [['id' => 'a'], ['id' => 'b']])
        ->assertJsonPath('meta.pagination', [
            'current_page' => 1,
            'per_page' => 20,
            'total' => 135,
            'last_page' => 7,
        ]);
});
