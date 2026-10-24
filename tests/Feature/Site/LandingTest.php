<?php

declare(strict_types=1);

use App\Enums\SalesLeadStatus;
use App\Models\Plan;
use App\Models\SalesLead;
use App\Support\SystemSettings;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('contact-m:127.0.0.1');
    RateLimiter::clear('contact-h:127.0.0.1');
});

function leadBody(array $override = []): array
{
    return ['name' => 'Budi', 'business_name' => 'Kopi Senja', 'phone' => '0812-3456 7890', 'city' => 'Bandung', 'business_type' => 'cafe', ...$override];
}

it('halaman depan menampilkan fitur, tombol masuk, dan form hubungi sales', function () {
    $this->get('/')->assertOk()
        ->assertSee('Kasir cepat, laporan rapi')
        ->assertSee('Split payment')
        ->assertSee('Masuk')
        ->assertSee('Kirim ke tim sales');
});

it('harga: hanya paket aktif, publik, dan dalam periode; harga & hemat tahunan dari master paket', function () {
    $this->seed(PlanSeeder::class);
    Plan::query()->create(['code' => 'promo-lalu', 'name' => 'Promo Lalu', 'price_monthly' => '50000', 'price_yearly' => '500000', 'limits' => [], 'available_until' => now()->subDay()]);
    Plan::query()->create(['code' => 'arsip', 'name' => 'Paket Arsip', 'price_monthly' => '10000', 'price_yearly' => '100000', 'limits' => [], 'is_active' => false]);

    $this->get('/')->assertOk()
        ->assertSee('id="pricing"', false)
        ->assertSeeInOrder(['Basic', 'Pro', 'Bisnis'])
        ->assertSee('199.000')
        ->assertSee('Rp1.990.000/tahun')
        ->assertSee('hemat 17%')
        ->assertDontSee('Enterprise')
        ->assertDontSee('Promo Lalu')
        ->assertDontSee('Paket Arsip');
});

it('harga: paket uji coba ditandai saat pendaftaran dibuka', function () {
    $this->seed(PlanSeeder::class);
    $settings = app(SystemSettings::class);
    $settings->setOnboarding(14, true);
    $settings->setOnboardingExtras(Plan::query()->where('code', 'pro')->value('id'), 20, [], [], null);

    $this->get('/')->assertOk()->assertSee('Paket uji coba')->assertSee('Coba gratis 14 hari');
});

it('harga: section disembunyikan bila belum ada paket publik', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('id="pricing"', false)
        ->assertDontSee('href="#pricing"', false);
});

it('pertanyaan umum tampil dengan jawaban yang jujur soal offline & masa uji coba', function () {
    $this->get('/')->assertOk()
        ->assertSee('id="faq"', false)
        ->assertSee('href="#faq"', false)
        ->assertSee('Apakah bisa dipakai tanpa internet?')
        ->assertSee('Akun menjadi hanya-baca, bukan dihapus.');
});

it('form hubungi sales menyimpan calon pelanggan', function () {
    $this->post('/contact-sales', leadBody(['message' => 'Dua kasir']))
        ->assertRedirect()
        ->assertSessionHas('contact_sent', true);

    $lead = SalesLead::query()->sole();
    expect($lead->business_name)->toBe('Kopi Senja')
        ->and($lead->phone)->toBe('081234567890')
        ->and($lead->status)->toBe(SalesLeadStatus::New)
        ->and($lead->ip_hash)->not->toBeNull()->not->toBe('127.0.0.1')
        ->and($lead->whatsappNumber())->toBe('6281234567890');
});

it('validasi form hubungi sales', function (array $override, string $field) {
    $this->post('/contact-sales', leadBody($override))->assertSessionHasErrors($field);

    expect(SalesLead::query()->count())->toBe(0);
})->with([
    'nama kosong' => [['name' => ''], 'name'],
    'bisnis kosong' => [['business_name' => ''], 'business_name'],
    'nomor tidak valid' => [['phone' => 'abc'], 'phone'],
    'email tidak valid' => [['email' => 'bukan-email'], 'email'],
    'jenis usaha tidak dikenal' => [['business_type' => 'pabrik'], 'business_type'],
]);

it('honeypot: bot mendapat respons sukses tapi tidak disimpan', function () {
    $this->post('/contact-sales', leadBody(['website' => 'http://spam.test']))->assertSessionHas('contact_sent', true);

    expect(SalesLead::query()->count())->toBe(0);
});

it('form dibatasi 3 kiriman per menit per IP', function () {
    foreach (range(1, 3) as $_) {
        $this->post('/contact-sales', leadBody())->assertRedirect();
    }

    $this->post('/contact-sales', leadBody())->assertStatus(429);
    expect(SalesLead::query()->count())->toBe(3);
});
