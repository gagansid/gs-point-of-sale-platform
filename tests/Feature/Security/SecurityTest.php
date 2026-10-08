<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::get('ping', fn () => ApiResponse::success());
        Route::get('users/count', fn (Request $r) => ApiResponse::success([
            'count' => User::allTenants()->where('email', $r->query('email'))->count(),
        ]));
    });
});

describe('request id', function () {
    it('membuat request id dan mengirimkannya di header serta meta', function () {
        $response = $this->getJson('/api/_test/ping');

        $id = $response->headers->get('X-Request-Id');
        expect($id)->toMatch('/^[0-9a-f-]{36}$/');
        $response->assertJsonPath('meta.request_id', $id);
    });

    it('memakai request id dari client yang formatnya aman', function () {
        $this->getJson('/api/_test/ping', ['X-Request-Id' => 'flutter-0192f3a1-abc'])
            ->assertHeader('X-Request-Id', 'flutter-0192f3a1-abc')
            ->assertJsonPath('meta.request_id', 'flutter-0192f3a1-abc');
    });

    it('mengganti request id berbahaya untuk mencegah log injection', function (string $malicious) {
        $id = $this->getJson('/api/_test/ping', ['X-Request-Id' => $malicious])->headers->get('X-Request-Id');

        expect($id)->not->toBe($malicious)->toMatch('/^[0-9a-f-]{36}$/');
    })->with([
        'skrip' => '<script>alert(1)</script>',
        'injeksi log' => 'abc def ERROR palsu',
        'terlalu panjang' => str_repeat('a', 200),
        'terlalu pendek' => 'abc',
    ]);
});

describe('header keamanan', function () {
    it('dipasang pada respons API', function () {
        $response = $this->getJson('/api/_test/ping');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeaderMissing('X-Powered-By');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('dipasang juga pada respons error dan halaman web', function () {
        $this->getJson('/api/v1/tidak-ada')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/up')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('tidak mengirim HSTS di luar production', function () {
        $this->getJson('/api/_test/ping')->assertHeaderMissing('Strict-Transport-Security');
    });
});

describe('CORS', function () {
    it('tidak mengizinkan origin asing memanggil API dari browser', function () {
        $response = $this->call('OPTIONS', '/api/_test/ping', server: [
            'HTTP_ORIGIN' => 'https://situs-phishing.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

describe('SQL injection', function () {
    it('input berbahaya diperlakukan sebagai data, bukan perintah SQL', function (string $payload) {
        User::factory()->count(3)->create();

        $this->getJson('/api/_test/users/count?email='.urlencode($payload))
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        expect(User::allTenants()->count())->toBe(3);
    })->with([
        "' OR '1'='1",
        "' OR 1=1 --",
        "'; DROP TABLE users; --",
        "' UNION SELECT password FROM users --",
    ]);
});

it('memakai Bahasa Indonesia dan zona waktu UTC', function () {
    expect(app()->getLocale())->toBe('id')
        ->and(config('app.timezone'))->toBe('UTC');
});
