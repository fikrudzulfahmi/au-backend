<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi CORS
|--------------------------------------------------------------------------
| 3.3 — CORS hanya mengizinkan origin FRONTEND_URL (bukan "*").
| Header yang diizinkan mencakup Authorization, X-Device-Token, Accept,
| dan Content-Type.
|
| `exposed_headers` memuat Content-Disposition: tanpa itu peramban MENYEMBUNYIKAN
| header tersebut dari JavaScript (hanya header safelisted yang dapat dibaca),
| sehingga frontend selalu gagal membaca nama berkas dari server dan jatuh ke nama
| cadangan. Akibatnya ekspor Excel terunduh bernama .pdf, dan ekspor PDF tampak benar
| hanya karena kebetulan nama cadangannya memang .pdf.
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

    'exposed_headers' => [
        'Content-Disposition',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,
];
