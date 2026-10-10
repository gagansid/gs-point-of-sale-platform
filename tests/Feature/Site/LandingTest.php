<?php

declare(strict_types=1);

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
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
