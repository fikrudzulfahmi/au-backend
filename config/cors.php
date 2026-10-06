<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi CORS
|--------------------------------------------------------------------------
| 3.3 — CORS hanya mengizinkan origin FRONTEND_URL (bukan "*").
| Header yang diizinkan mencakup Authorization, X-Device-Token, Accept,
| dan Content-Type.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173'))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Device-Token',
        'X-Requested-With',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
