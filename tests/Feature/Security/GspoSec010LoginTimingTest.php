<?php

declare(strict_types=1);

use App\Support\DummyPasswordHash;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;

/*
 * GSPOS-SEC-010 (docs/security/reports/2026-10-10-audit.md): login dengan email tidak terdaftar
 * tidak boleh menjalankan bcrypt tambahan (Hash::make) — selisih waktunya membocorkan keberadaan akun.
 * Kedua jalur harus menjalankan tepat satu Hash::check. Mode subdomain (login web hanya di gspos.id/login).
 */
const SEC010_SUBDOMAIN_ENV = [
    'POS_MAIN_DOMAIN' => 'gspos.localhost',
    'POS_APP_DOMAIN' => 'app.gspos.localhost',
    'POS_ADMIN_DOMAIN' => 'admin.gspos.localhost',
    'POS_API_DOMAIN' => 'api.gspos.localhost',
];

beforeAll(function () {
    foreach (SEC010_SUBDOMAIN_ENV as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
});

afterAll(function () {
    foreach (array_keys(SEC010_SUBDOMAIN_ENV) as $key) {
        putenv("{$key}=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }
});

beforeEach(function () {
    DummyPasswordHash::get(); // hash dummy sudah ada (seperti setelah request pertama di produksi)

    // Penghitung panggilan bcrypt; perilaku hashing tetap asli
    $this->hasher = new class(app()) extends HashManager
    {
        public int $makes = 0;

        public int $checks = 0;

        public function make(#[SensitiveParameter] $value, array $options = []): string
        {
            $this->makes++;

            return parent::make($value, $options);
        }

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
        {
            $this->checks++;

            return parent::check($value, $hashedValue, $options);
        }
    };
    Hash::swap($this->hasher);
});

it('login API email tak terdaftar: satu Hash::check, tanpa Hash::make', function () {
    $this->postJson('http://api.gspos.localhost/v1/auth/login', ['email' => 'siapa@kopi.test', 'password' => 'salahsalah', 'device_uid' => 'x'], apiHeaders())
        ->assertStatus(401);

    expect($this->hasher->makes)->toBe(0)->and($this->hasher->checks)->toBe(1);
});

it('login web email tak terdaftar: satu Hash::check, tanpa Hash::make', function () {
    $this->post('http://gspos.localhost/login', ['email' => 'siapa@kopi.test', 'password' => 'salahsalah'])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);

    expect($this->hasher->makes)->toBe(0)->and($this->hasher->checks)->toBe(1);
});

it('hash dummy memakai cost bcrypt yang berlaku', function () {
    expect(Hash::info(DummyPasswordHash::get())['options']['cost'] ?? null)->toBe((int) config('hashing.bcrypt.rounds'));
});
