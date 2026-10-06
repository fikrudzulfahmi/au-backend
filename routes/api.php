<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Akademik\JadwalController;
use App\Http\Controllers\Api\V1\Akademik\JamPelajaranController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Jurnal\JurnalController;
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
use App\Http\Controllers\Api\V1\Plotting\PlottingKelasController;
use App\Http\Controllers\Api\V1\Plotting\PlottingMapelController;
use App\Http\Controllers\Api\V1\Presensi\JamKerjaController;
use App\Http\Controllers\Api\V1\Presensi\LokasiPresensiController;
use App\Http\Controllers\Api\V1\Presensi\MonitoringPresensiController;
use App\Http\Controllers\Api\V1\Presensi\PengajuanIzinController;
use App\Http\Controllers\Api\V1\Presensi\PengajuanLuarRadiusController;
use App\Http\Controllers\Api\V1\Presensi\PresensiController;
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

        /* ===================================================================
         | FASE 2 — Plotting & jadwal (matriks Bagian 2)
         |
         | Plotting Kelas        : admin kelola; kepala_sekolah & wakasek lihat.
         | Plotting Mapel        : admin & wakasek kelola; kepala_sekolah lihat;
         |                         guru hanya data miliknya (L/S).
         | Jam pelajaran         : admin & wakasek kelola; kepala_sekolah lihat.
         | Jadwal pelajaran      : admin & wakasek kelola; kepala_sekolah lihat;
         |                         guru hanya jadwal miliknya (L/S).
         |================================================================= */

        /* ------------------------------------------- plotting kelas (FR-PLK) */
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum')->group(function (): void {
            // Rute harfiah didahulukan agar tidak tertukar dengan /plotting-kelas/{id}.
            Route::get('/plotting-kelas/ringkasan', [PlottingKelasController::class, 'ringkasan']);
            Route::get('/plotting-kelas/belum-terplot', [PlottingKelasController::class, 'belumTerplot']);
            Route::get('/plotting-kelas/ekspor', [PlottingKelasController::class, 'ekspor']);
            Route::get('/plotting-kelas', [PlottingKelasController::class, 'index']);
            Route::get('/siswa/{siswa}/riwayat-kelas', [PlottingKelasController::class, 'riwayat']);
        });

        Route::middleware('peran:admin')->group(function (): void {
            Route::post('/plotting-kelas/import', [PlottingKelasController::class, 'import']);
            Route::post('/plotting-kelas/wizard/pratinjau', [PlottingKelasController::class, 'wizardPratinjau']);
            Route::post('/plotting-kelas/wizard/eksekusi', [PlottingKelasController::class, 'wizardEksekusi']);
            Route::post('/plotting-kelas', [PlottingKelasController::class, 'store']);
            Route::post('/plotting-kelas/{plottingKelas}/mutasi', [PlottingKelasController::class, 'mutasi']);
            Route::post('/plotting-kelas/{plottingKelas}/batalkan', [PlottingKelasController::class, 'batalkan']);
            Route::delete('/plotting-kelas/{plottingKelas}', [PlottingKelasController::class, 'destroy']);
        });

        /* ------------------------------------------- plotting mapel (FR-PLM) */
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum,guru')->group(function (): void {
            Route::get('/plotting-mapel/matriks', [PlottingMapelController::class, 'matriks']);
            Route::get('/plotting-mapel/per-guru', [PlottingMapelController::class, 'perGuru']);
            Route::get('/plotting-mapel/ekspor', [PlottingMapelController::class, 'ekspor']);
            Route::get('/plotting-mapel', [PlottingMapelController::class, 'index']);
        });

        Route::middleware('peran:admin,wakasek_kurikulum')->group(function (): void {
            Route::post('/plotting-mapel/salin', [PlottingMapelController::class, 'salin']);
            Route::post('/plotting-mapel', [PlottingMapelController::class, 'store']);
            Route::put('/plotting-mapel/{plottingMapel}', [PlottingMapelController::class, 'update']);
            Route::delete('/plotting-mapel/{plottingMapel}', [PlottingMapelController::class, 'destroy']);
        });

        /* ------------------------------------------- jam pelajaran (FR-JAM) */
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum')->group(function (): void {
            Route::get('/jam-pelajaran', [JamPelajaranController::class, 'index']);
        });

        Route::middleware('peran:admin,wakasek_kurikulum')->group(function (): void {
            Route::post('/jam-pelajaran/salin', [JamPelajaranController::class, 'salin']);
            Route::put('/jam-pelajaran/slot/{slotJam}', [JamPelajaranController::class, 'updateSlot']);
            Route::delete('/jam-pelajaran/slot/{slotJam}', [JamPelajaranController::class, 'destroySlot']);
            Route::post('/jam-pelajaran/{polaJam}/slot', [JamPelajaranController::class, 'storeSlot']);
            Route::post('/jam-pelajaran', [JamPelajaranController::class, 'store']);
            Route::put('/jam-pelajaran/{polaJam}', [JamPelajaranController::class, 'update']);
            Route::delete('/jam-pelajaran/{polaJam}', [JamPelajaranController::class, 'destroy']);
        });

        /* ----------------------------------------------- jadwal (FR-JDW) */
        // FR-JDW-06 — guru melihat jadwal hari ini dan mingguan miliknya.
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum,guru')->group(function (): void {
            Route::get('/jadwal/hari-ini', [JadwalController::class, 'hariIni']);
            Route::get('/jadwal/mingguan', [JadwalController::class, 'mingguan']);
            Route::get('/jadwal', [JadwalController::class, 'index']);
        });

        Route::middleware('peran:admin,wakasek_kurikulum')->group(function (): void {
            Route::get('/jadwal/ekspor', [JadwalController::class, 'ekspor']);
            Route::get('/jadwal/peringatan', [JadwalController::class, 'peringatan']);
            Route::post('/jadwal', [JadwalController::class, 'store']);
            Route::delete('/jadwal/{jadwal}', [JadwalController::class, 'destroy']);
        });

        /* ------------------------------------- presensi pegawai (FR-PRS) */
        // Bagian 2: presensi milik pegawai (guru/struktural, dan kepsek/wakasek bila
        // ia juga terhubung ke data pegawai). Akun tanpa data pegawai ditolak oleh
        // PresensiService dengan pesan yang jelas, bukan 403 yang membingungkan.
        Route::prefix('presensi')->group(function (): void {
            Route::get('/hari-ini', [PresensiController::class, 'hariIni']);
            Route::post('/masuk', [PresensiController::class, 'masuk']);
            Route::post('/pulang', [PresensiController::class, 'pulang']);
            Route::get('/riwayat', [PresensiController::class, 'riwayat']);

            // Foto disimpan di disk privat; penyajiannya tetap melewati otorisasi.
            Route::get('/{presensi}/foto/{sisi}', [PresensiController::class, 'foto'])
                ->whereIn('sisi', ['masuk', 'pulang']);
        });

        /* --------------------------------------- pengajuan (FR-IZN) */
        Route::prefix('pengajuan-izin')->group(function (): void {
            Route::get('/', [PengajuanIzinController::class, 'index']);
            Route::post('/', [PengajuanIzinController::class, 'store']);
            Route::patch('/{pengajuanIzin}/batalkan', [PengajuanIzinController::class, 'batalkan']);

            // FR-IZN-08 — admin membuat pengajuan atas nama pegawai.
            Route::post('/atas-nama/{pegawai}', [PengajuanIzinController::class, 'storeAtasNama'])
                ->middleware('peran:admin');

            // FR-IZN-04 — penyetuju: admin atau kepala sekolah.
            Route::patch('/{pengajuanIzin}/putuskan', [PengajuanIzinController::class, 'putuskan'])
                ->middleware('peran:admin,kepala_sekolah');
        });

        Route::prefix('pengajuan-luar-radius')->group(function (): void {
            Route::get('/', [PengajuanLuarRadiusController::class, 'index']);
            Route::post('/', [PengajuanLuarRadiusController::class, 'store']);
            Route::patch('/{pengajuanLuarRadius}/putuskan', [PengajuanLuarRadiusController::class, 'putuskan'])
                ->middleware('peran:admin,kepala_sekolah');
        });

        /* ------------------------ monitoring & persetujuan presensi (FR-PRS-10/11/13) */
        // Lihat: admin, kepala sekolah, wakasek (Bagian 2).
        Route::middleware('peran:admin,kepala_sekolah,wakasek_kurikulum')->prefix('monitoring')->group(function (): void {
            Route::get('/presensi-harian', [MonitoringPresensiController::class, 'harian']);
            Route::get('/presensi-harian/{presensi}', [MonitoringPresensiController::class, 'detail']);
            Route::get('/persetujuan-presensi', [MonitoringPresensiController::class, 'antrean']);
        });

        // Memutuskan & mengoreksi: admin dan kepala sekolah.
        Route::middleware('peran:admin,kepala_sekolah')->group(function (): void {
            Route::patch('/monitoring/presensi-harian/{presensi}/putuskan', [MonitoringPresensiController::class, 'putuskan']);
            Route::patch('/monitoring/presensi-harian/{presensi}/koreksi', [MonitoringPresensiController::class, 'koreksi']);
            Route::post('/monitoring/persetujuan-presensi/massal', [MonitoringPresensiController::class, 'putuskanMassal']);
        });

        /* ------------------------------- pengaturan lokasi & jam kerja (FR-LOK) */
        Route::middleware('peran:admin')->prefix('pengaturan/lokasi')->group(function (): void {
            Route::get('/pegawai', [LokasiPresensiController::class, 'pegawai']);
            Route::post('/tetapkan', [LokasiPresensiController::class, 'tetapkan']);
            Route::get('/', [LokasiPresensiController::class, 'index']);
            Route::post('/', [LokasiPresensiController::class, 'store']);
            Route::put('/{lokasiPresensi}', [LokasiPresensiController::class, 'update']);
            Route::patch('/{lokasiPresensi}/default', [LokasiPresensiController::class, 'jadikanDefault']);
            Route::delete('/{lokasiPresensi}', [LokasiPresensiController::class, 'destroy']);
        });

        Route::middleware('peran:admin')->prefix('jam-kerja')->group(function (): void {
            Route::get('/', [JamKerjaController::class, 'index']);
            Route::post('/', [JamKerjaController::class, 'simpan']);
        });

        /* --------------------------------- jurnal & presensi siswa (FR-JRN) */
        // Matriks akses Bagian 2 baris "Jurnal + presensi siswa":
        //   admin = K** (boleh mengoreksi, setiap koreksi tercatat di audit_log)
        //   guru  = K(S), hanya sesi pada jadwalnya sendiri
        //   kepala sekolah & wakasek = TIDAK ada akses ke endpoint ini; keduanya
        //   melihatnya lewat laporan pada fase berikutnya.
        Route::middleware('peran:guru,admin')->prefix('jurnal')->group(function (): void {
            // FR-JRN-09 — riwayat jurnal milik sendiri, filter periode/kelas/mapel.
            Route::get('/', [JurnalController::class, 'index']);

            // FR-JRN-01 — sesi hari ini dari jadwal, beserta status jurnalnya.
            Route::get('/sesi-hari-ini', [JurnalController::class, 'sesiHariIni']);

            // FR-JRN-08 / BR-22 — daftar siswa kelas untuk halaman isi jurnal.
            Route::get('/siswa-kelas', [JurnalController::class, 'siswaKelas']);

            // FR-JRN-10 — rekap presensi siswa (wali kelas: kelasnya sendiri, admin: semua).
            Route::get('/rekap-siswa', [JurnalController::class, 'rekapSiswa']);

            // Foto kegiatan tersimpan di disk privat; penyajiannya lewat otorisasi.
            Route::get('/foto/{foto}', [JurnalController::class, 'foto']);

            Route::post('/', [JurnalController::class, 'simpan']);

            // Rute literal di atas didaftarkan lebih dulu; batas angka menjaga
            // '/siswa-kelas' dan '/sesi-hari-ini' tidak tertangkap sebagai {jurnal}.
            Route::get('/{jurnal}', [JurnalController::class, 'tampil'])->whereNumber('jurnal');
            Route::put('/{jurnal}', [JurnalController::class, 'perbarui'])->whereNumber('jurnal');
            Route::get('/{jurnal}/presensi-siswa', [JurnalController::class, 'presensiSiswa'])->whereNumber('jurnal');
        });
    });
});
