<?php

declare(strict_types=1);

/*
 * API dipakai aplikasi Flutter (bukan browser), sehingga tidak butuh CORS.
 * Default: tidak ada origin yang diizinkan. Tambahkan origin web tertentu lewat
 * CORS_ALLOWED_ORIGINS (pisahkan dengan koma) bila kelak ada klien browser.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-App-Version', 'X-Request-Id', 'X-Device-Uid', 'If-None-Match'],

    'exposed_headers' => ['X-Request-Id', 'ETag', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
