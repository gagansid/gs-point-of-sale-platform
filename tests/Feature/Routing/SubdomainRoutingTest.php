<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

/*
 * Mode subdomain (ADR 0008). Env dipasang di beforeAll — sebelum aplikasi test dibuat — agar
 * route panel & API terdaftar persis seperti di produksi (gspos.id, app., admin., api.).
 */
const SUBDOMAIN_ENV = [
    'POS_MAIN_DOMAIN' => 'gspos.localhost',
    'POS_APP_DOMAIN' => 'app.gspos.localhost',
    'POS_ADMIN_DOMAIN' => 'admin.gspos.localhost',
    'POS_API_DOMAIN' => 'api.gspos.localhost',
];

beforeAll(function () {
    foreach (SUBDOMAIN_ENV as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

afterAll(function () {
    // Kembali ke mode satu domain seperti phpunit.xml (kosong, bukan dihapus: .env lokal bisa berisi domain)
    foreach (array_keys(SUBDOMAIN_ENV) as $key) {
        putenv("{$key}=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }
});

it('panel pelanggan & admin berada di root subdomain masing-masing', function () {
    $hostAndPath = fn (?string $url): string => parse_url((string) $url, PHP_URL_HOST).parse_url((string) $url, PHP_URL_PATH);

    expect($hostAndPath(Filament::getPanel('dashboard')->getLoginUrl()))->toBe('app.gspos.localhost/login')
        ->and($hostAndPath(Filament::getPanel('admin')->getLoginUrl()))->toBe('admin.gspos.localhost/login');

    // Login pelanggan dipusatkan di gspos.id/login; login admin tetap di subdomain admin
    $this->get('http://app.gspos.localhost/login')->assertRedirect('http://gspos.localhost/login');
    $this->get('http://admin.gspos.localhost/login')->assertOk();
});

it('setelah login owner membuka /products langsung tanpa /dashboard', function () {
    $owner = User::factory()->owner()->create();
    Outlet::factory()->create(['tenant_id' => $owner->tenant_id]);
    $this->actingAs($owner);

    $this->get('http://app.gspos.localhost/products')->assertOk();
    $this->get('http://app.gspos.localhost/dashboard/products')->assertNotFound();
});

it('halaman admin tidak bisa dibuka dari subdomain pelanggan', function () {
    $this->get('http://app.gspos.localhost/tenants')->assertNotFound();
    $this->get('http://gspos.localhost/tenants')->assertNotFound();
});

it('API hanya di api.gspos.localhost/v1', function () {
    $this->getJson('http://api.gspos.localhost/v1/system/status')->assertOk()->assertJsonPath('success', true);
    $this->getJson('http://app.gspos.localhost/v1/system/status')->assertNotFound();
});

it('URL lama dialihkan ke subdomain baru dengan path & query', function () {
    $this->get('http://gspos.localhost/dashboard/products?page=2')
        ->assertStatus(301)
        ->assertRedirect('http://app.gspos.localhost/products?page=2');

    $this->get('http://gspos.localhost/admin/tenants')
        ->assertStatus(301)
        ->assertRedirect('http://admin.gspos.localhost/tenants');

    // 308: aplikasi lama yang POST ke /api/v1 tetap terkirim dengan metode & body yang sama
    $this->post('http://gspos.localhost/api/v1/auth/login')
        ->assertStatus(308)
        ->assertRedirect('http://api.gspos.localhost/v1/auth/login');
});

it('domain utama menampilkan halaman depan & login pelanggan, bukan panel', function () {
    $this->get('http://gspos.localhost/')->assertOk()->assertSee('Hubungi sales')->assertSee('http://gspos.localhost/login', escape: false);
    $this->get('http://gspos.localhost/login')->assertOk()->assertSee('Masuk ke akun Anda');
    $this->get('http://gspos.localhost/products')->assertNotFound();
});

it('URL gambar produk di API absolut mengikuti host api., file disajikan relatif di semua subdomain', function () {
    $product = Product::factory()->make(['image_path' => 'products/x/kopi.png']);

    expect(Storage::disk('public')->url('products/x/kopi.png'))->toBe('/storage/products/x/kopi.png');

    $this->get('http://api.gspos.localhost/v1/system/status');
    expect(parse_url((string) $product->imageUrl(), PHP_URL_PATH))->toBe('/storage/products/x/kopi.png');
});

it('header keamanan khusus API tetap terpasang di api.gspos.localhost/v1', function () {
    $response = $this->getJson('http://api.gspos.localhost/v1/system/status')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});
