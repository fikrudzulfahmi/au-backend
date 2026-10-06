<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PublikSekolahController;
use App\Http\Controllers\Api\V1\WaktuServerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute API SIPANDU — prefix /api/v1 (3.4)
|--------------------------------------------------------------------------
| Backend adalah sumber kebenaran untuk validasi dan otorisasi.
| Frontend hanya klien: menyembunyikan menu bukan pengganti otorisasi server.
*/

Route::prefix('v1')->group(function (): void {
    // Publik
    Route::get('/waktu-server', [WaktuServerController::class, 'show'])->name('waktu-server');
    Route::get('/publik/sekolah', [PublikSekolahController::class, 'show'])->name('publik.sekolah');

    // Autentikasi — pembatasan laju login 5/menit/IP (3.4, FR-SEC-01)
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/ganti-password', [AuthController::class, 'gantiPassword'])
            ->name('auth.ganti-password');
    });
});
