<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Master\HariLiburController;
use App\Http\Controllers\Api\V1\Master\JurusanController;
use App\Http\Controllers\Api\V1\Master\KelasController;
use App\Http\Controllers\Api\V1\Master\MapelController;
use App\Http\Controllers\Api\V1\Master\PegawaiController;
use App\Http\Controllers\Api\V1\Master\SemesterController;
use App\Http\Controllers\Api\V1\Master\SiswaController;
use App\Http\Controllers\Api\V1\Master\TahunPelajaranController;
use App\Http\Controllers\Api\V1\Pengaturan\AuditLogController;
use App\Http\Controllers\Api\V1\Pengaturan\PengaturanSekolahController;
use App\Http\Controllers\Api\V1\Pengaturan\PenggunaController;
use App\Http\Controllers\Api\V1\PublikSekolahController;
use App\Http\Controllers\Api\V1\WaktuServerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute API SIPANDU — prefix /api/v1 (3.4)
|--------------------------------------------------------------------------
| Backend adalah sumber kebenaran untuk validasi dan otorisasi.
| Middleware `peran` menegakkan matriks akses Bagian 2 di server; menyembunyikan
| menu di frontend BUKAN pengganti otorisasi.
|
| Matriks master data: admin = kelola (K); kepala_sekolah & wakasek_kurikulum = lihat (L).
| Pengaturan sistem, info sekolah, pengguna, dan audit log: admin saja.
*/

Route::prefix('v1')->group(function (): void {
    /* ---------------------------------------------------------------- publik */
    Route::get('/waktu-server', [WaktuServerController::class, 'show'])->name('waktu-server');
    Route::get('/publik/sekolah', [PublikSekolahController::class, 'show'])->name('publik.sekolah');

    /* ---------------------------------------------- autentikasi (3.4, FR-SEC) */
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/ganti-password', [AuthController::class, 'gantiPassword'])->name('auth.ganti-password');

        /* ------------------------------------------------ master data — LIHAT */
        // L pada matriks Bagian 2: admin, kepala_sekolah, wakasek_kurikulum.
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum')->group(function (): void {
            Route::get('/tahun-pelajaran', [TahunPelajaranController::class, 'index']);
            Route::get('/tahun-pelajaran/{tahun_pelajaran}', [TahunPelajaranController::class, 'show']);

            Route::get('/hari-libur', [HariLiburController::class, 'index']);
            Route::get('/jurusan', [JurusanController::class, 'index']);
            Route::get('/kelas', [KelasController::class, 'index']);
            Route::get('/mapel', [MapelController::class, 'index']);

            Route::get('/siswa', [SiswaController::class, 'index']);
            Route::get('/pegawai', [PegawaiController::class, 'index']);
        });

        /* ---------------------------------------------- master data — KELOLA */
        // K pada matriks Bagian 2: hanya admin.
        Route::middleware('peran:admin')->group(function (): void {
            Route::post('/tahun-pelajaran', [TahunPelajaranController::class, 'store']);
            Route::put('/tahun-pelajaran/{tahun_pelajaran}', [TahunPelajaranController::class, 'update']);
            Route::patch('/tahun-pelajaran/{tahun_pelajaran}', [TahunPelajaranController::class, 'update']);
            Route::delete('/tahun-pelajaran/{tahun_pelajaran}', [TahunPelajaranController::class, 'destroy']);
            Route::post('/tahun-pelajaran/{tahun_pelajaran}/aktifkan', [TahunPelajaranController::class, 'aktifkan']);
            Route::post('/tahun-pelajaran/{tahun_pelajaran}/selesai', [TahunPelajaranController::class, 'tandaiSelesai']);
            Route::post('/tahun-pelajaran/{tahun_pelajaran}/salin', [TahunPelajaranController::class, 'salin']);

            Route::put('/semester/{semester}', [SemesterController::class, 'update']);
            Route::patch('/semester/{semester}', [SemesterController::class, 'update']);

            Route::post('/hari-libur', [HariLiburController::class, 'store']);
            Route::put('/hari-libur/{hari_libur}', [HariLiburController::class, 'update']);
            Route::delete('/hari-libur/{hari_libur}', [HariLiburController::class, 'destroy']);

            Route::post('/jurusan', [JurusanController::class, 'store']);
            Route::put('/jurusan/{jurusan}', [JurusanController::class, 'update']);
            Route::delete('/jurusan/{jurusan}', [JurusanController::class, 'destroy']);

            Route::post('/kelas', [KelasController::class, 'store']);
            Route::post('/kelas/salin', [KelasController::class, 'salin']);
            Route::put('/kelas/{kelas}', [KelasController::class, 'update']);
            Route::delete('/kelas/{kelas}', [KelasController::class, 'destroy']);

            Route::post('/mapel', [MapelController::class, 'store']);
            Route::put('/mapel/{mapel}', [MapelController::class, 'update']);
            Route::delete('/mapel/{mapel}', [MapelController::class, 'destroy']);

            /* -------------------------------------------- siswa (FR-SIS-03/04) */
            // Rute harfiah didahulukan agar tidak tertukar dengan /siswa/{siswa}.
            Route::get('/siswa/template', [SiswaController::class, 'template']);
            Route::get('/siswa/ekspor', [SiswaController::class, 'ekspor']);
            Route::post('/siswa/import', [SiswaController::class, 'import']);
            Route::get('/siswa/{siswa}', [SiswaController::class, 'show']);
            Route::post('/siswa', [SiswaController::class, 'store']);
            Route::put('/siswa/{siswa}', [SiswaController::class, 'update']);
            Route::delete('/siswa/{siswa}', [SiswaController::class, 'destroy']);

            /* ------------------------------------------ pegawai (FR-PEG-01..06) */
            Route::get('/pegawai/template', [PegawaiController::class, 'template']);
            Route::get('/pegawai/ekspor', [PegawaiController::class, 'ekspor']);
            Route::post('/pegawai/import', [PegawaiController::class, 'import']);
            Route::get('/pegawai/{pegawai}', [PegawaiController::class, 'show']);
            Route::post('/pegawai', [PegawaiController::class, 'store']);
            Route::put('/pegawai/{pegawai}', [PegawaiController::class, 'update']);
            Route::delete('/pegawai/{pegawai}', [PegawaiController::class, 'destroy']);
            Route::post('/pegawai/{pegawai}/akun', [PegawaiController::class, 'buatAkun']);
            Route::post('/pegawai/{pegawai}/reset-password', [PegawaiController::class, 'resetPassword']);
            Route::post('/pegawai/{pegawai}/reset-perangkat', [PegawaiController::class, 'resetPerangkat']);

            /* ---------------------------------------------------- mapel ekspor */
            Route::get('/mapel/ekspor', [MapelController::class, 'ekspor']);

            /* ------------------------------------------------- pengaturan (5.15) */
            Route::get('/pengaturan/sekolah', [PengaturanSekolahController::class, 'show']);
            Route::post('/pengaturan/sekolah', [PengaturanSekolahController::class, 'update']);
            Route::post('/pengaturan/sekolah/penandatangan-default', [PengaturanSekolahController::class, 'jadikanPenandatanganDefault']);
            Route::get('/pengaturan/landing', [PengaturanSekolahController::class, 'landing']);
            Route::put('/pengaturan/landing', [PengaturanSekolahController::class, 'simpanLanding']);
            Route::get('/pengaturan/sistem', [PengaturanSekolahController::class, 'sistem']);
            Route::put('/pengaturan/sistem', [PengaturanSekolahController::class, 'simpanSistem']);

            Route::get('/pengaturan/audit-log', [AuditLogController::class, 'index']);
            Route::get('/pengaturan/audit-log/aksi', [AuditLogController::class, 'aksi']);

            Route::get('/pengaturan/pengguna', [PenggunaController::class, 'index']);
            Route::get('/pengaturan/pengguna/peran', [PenggunaController::class, 'peran']);
            Route::put('/pengaturan/pengguna/{pengguna}', [PenggunaController::class, 'update']);
            Route::post('/pengaturan/pengguna/{pengguna}/reset-perangkat', [PenggunaController::class, 'resetPerangkat']);
        });
    });
});
