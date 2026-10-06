<?php

declare(strict_types=1);

use App\Exceptions\AturanBisnisException;
use App\Http\Middleware\CatatPermintaanApi;
use App\Http\Middleware\PastikanPeran;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 3.2 — middleware `peran` untuk otorisasi berbasis peran (dicek di server).
        $middleware->alias([
            'peran' => PastikanPeran::class,
        ]);

        // 9 — pencatatan permintaan API.
        $middleware->api(append: [
            CatatPermintaanApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 3.4 — seluruh respons error API berformat JSON Bahasa Indonesia.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        });

        // Aturan bisnis (BR-xx/FR-xx) — pesan dan kode spesifik, bukan 500.
        $exceptions->render(function (AturanBisnisException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $muatan = [
                'message' => $e->getMessage(),
                'code' => $e->kode,
            ];

            if ($e->galat !== []) {
                $muatan['errors'] = $e->galat;
            }

            return response()->json($muatan, $e->getStatusCode());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Anda belum terautentikasi. Silakan masuk terlebih dahulu.',
                'code' => 'TIDAK_TERAUTENTIKASI',
            ], 401);
        });

        /*
         * Seluruh HttpException ditangani di satu tempat.
         * Laravel mengubah AuthorizationException menjadi 403 sebelum sampai ke sini,
         * sehingga pemeriksaan dilakukan lewat pengecualian sebelumnya.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $sebelumnya = $e->getPrevious();
            $pesan = $e->getMessage();

            if ($status === 404) {
                return response()->json([
                    'message' => 'Data yang diminta tidak ditemukan.',
                    'code' => 'TIDAK_DITEMUKAN',
                ], 404);
            }

            if ($status === 409) {
                return response()->json([
                    'message' => $pesan !== '' ? $pesan : 'Data ini bertentangan dengan aturan yang berlaku.',
                    'code' => 'KONFLIK_DATA',
                ], 409);
            }

            if ($status === 403) {
                return response()->json([
                    'message' => $pesan !== ''
                        ? $pesan
                        : 'Anda tidak berwenang mengakses data ini.',
                    'code' => 'TIDAK_BERWENANG',
                ], 403);
            }

            if ($status === 429) {
                return response()->json([
                    'message' => 'Terlalu banyak percobaan. Silakan coba lagi beberapa saat lagi.',
                    'code' => 'TERLALU_BANYAK_PERCOBAAN',
                ], 429);
            }

            return response()->json([
                'message' => $pesan !== '' ? $pesan : 'Permintaan tidak dapat diproses.',
                'code' => 'KESALAHAN_HTTP_'.$status,
            ], $status);
        });
    })->create();
