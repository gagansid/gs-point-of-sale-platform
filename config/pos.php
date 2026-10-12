<?php

declare(strict_types=1);

/*
 * Setelan bisnis POS. Nilai per outlet (pajak, service, pembulatan, batas diskon) disimpan di
 * tabel outlets; nilai di sini hanya default saat outlet baru dibuat dan batas teknis.
 */
return [

    /*
     * Subdomain per bagian aplikasi (ADR 0008, App\Support\Domains). Kosongkan POS_APP_DOMAIN
     * untuk memakai satu domain dengan path /dashboard, /admin, /api/v1.
     */
    'domains' => [
        'main' => env('POS_MAIN_DOMAIN'),
        'app' => env('POS_APP_DOMAIN'),
        'admin' => env('POS_ADMIN_DOMAIN'),
        'api' => env('POS_API_DOMAIN'),
    ],

    // Bantuan di menu akun dashboard (App\Support\Support): WhatsApp tim gs.POS (628…) atau email
    'support' => [
        'whatsapp' => env('POS_SUPPORT_WHATSAPP'),
        'email' => env('POS_SUPPORT_EMAIL', 'halo@gspos.id'),
    ],

    // Edisi (ADR 0009, App\Support\Edition): saas | self_hosted (jual putus, satu bisnis)
    'edition' => env('POS_EDITION', 'saas'),

    // Installer web /install (ADR 0016, App\Support\Installer\Installation)
    'installer' => [
        // false = dianggap selalu terinstal (test, atau instal manual lewat terminal)
        'enabled' => (bool) env('POS_INSTALLER', true),
        // Kode instal opsional: bila diisi, form instal memintanya sebelum langkah pertama
        'token' => env('INSTALL_TOKEN'),
        'lock_path' => storage_path('app/installed.json'),
        'state_path' => storage_path('app/install-state.json'),
    ],

    // Onboarding (ADR 0009). Nilai awal; super admin mengubahnya di Sistem → Pendaftaran
    'onboarding' => [
        'trial_days' => (int) env('POS_TRIAL_DAYS', 14),
        'signup_enabled' => (bool) env('POS_SIGNUP_ENABLED', true),
    ],

    // Zona waktu default outlet baru (ADR 0001). Server sendiri selalu UTC.
    'default_timezone' => env('POS_DEFAULT_TIMEZONE', 'Asia/Jakarta'),

    // Masa berlaku token Sanctum dalam menit
    'tokens' => [
        'cashier_ttl' => (int) env('POS_CASHIER_TOKEN_TTL', 16 * 60),
        'owner_ttl' => (int) env('POS_OWNER_TOKEN_TTL', 30 * 24 * 60),
    ],

    // Proxy tepercaya (GSPOS-SEC-015): kosong = tanpa proxy, "cloudflare", atau IP/CIDR dipisah koma
    'trusted_proxies' => env('POS_TRUSTED_PROXIES', ''),

    // Penguncian PIN: dikunci setelah N kali salah selama M menit
    'pin' => [
        'length' => 6,
        // Per (user, sumber: login/approval per perangkat), per user (semua perangkat), per perangkat (semua user)
        'max_attempts' => 5,
        'max_attempts_total' => 15,
        'max_attempts_per_device' => 20,
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

    // Batas outlet aktif bisnis baru di edisi SaaS (ADR 0010); admin bisa mengubah per tenant
    'default_max_outlets' => (int) env('POS_DEFAULT_MAX_OUTLETS', 1),

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
    // Kasir web (Q54): maks. browser aktif per outlet (cookie bisa dihapus untuk membuat device baru)
    'web_cashier' => [
        'max_devices_per_outlet' => (int) env('POS_WEB_CASHIER_MAX_DEVICES', 10),
    ],

    // Cache agregat laporan (R2, CachedReportService); 0 = nonaktif
    'reports' => [
        'cache_seconds' => (int) env('POS_REPORT_CACHE_SECONDS', 60),
    ],

    // Ringkasan harian email (Q57): dikirim setelah jam ini (waktu outlet), berisi rekap kemarin
    'daily_summary' => [
        'hour' => (int) env('POS_DAILY_SUMMARY_HOUR', 7),
    ],

    'rate_limits' => [
        'auth' => 5,
        'api' => 120,
        // Export laporan (Excel/PDF) per user per menit (R3)
        'export' => 10,
    ],

];
