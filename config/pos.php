<?php

declare(strict_types=1);

/*
 * Setelan bisnis POS. Nilai per outlet (pajak, service, pembulatan, batas diskon) disimpan di
 * tabel outlets; nilai di sini hanya default saat outlet baru dibuat dan batas teknis.
 */
return [

    // Zona waktu default outlet baru (ADR 0001). Server sendiri selalu UTC.
    'default_timezone' => env('POS_DEFAULT_TIMEZONE', 'Asia/Jakarta'),

    // Masa berlaku token Sanctum dalam menit
    'tokens' => [
        'cashier_ttl' => (int) env('POS_CASHIER_TOKEN_TTL', 16 * 60),
        'owner_ttl' => (int) env('POS_OWNER_TOKEN_TTL', 30 * 24 * 60),
    ],

    // Penguncian PIN: dikunci setelah N kali salah selama M menit
    'pin' => [
        'length' => 6,
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    // Default outlet baru
    'outlet_defaults' => [
        'tax_rate' => '0.00',
        'service_charge_rate' => '0.00',
        'tax_inclusive' => false,
        'rounding' => 0,
        'discount_limits' => ['cashier' => 10, 'supervisor' => 25],
    ],

    // Pagination API: default & batas maksimal per_page
    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100,
    ],

    'security' => [
        // Default sebelum super admin mengubahnya di Sistem → Keamanan. Aman secara default: wajib 2FA.
        'admin_two_factor_required' => (bool) env('POS_ADMIN_2FA_REQUIRED', true),
    ],

    // Rate limit per menit
    'rate_limits' => [
        'auth' => 5,
        'api' => 120,
    ],

];
