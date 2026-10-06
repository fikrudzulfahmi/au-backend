<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * FR-SEC-05 — mencatat aksi penting ke `audit_log`.
 * Tabel ini tidak dapat diubah dari UI (9 — pencatatan).
 */
class AuditLogService
{
    public const AKSI_LOGIN_GAGAL = 'login_gagal';

    public const AKSI_LOGIN = 'login';

    public const AKSI_LOGOUT = 'logout';

    public const AKSI_GANTI_PASSWORD = 'ganti_password';

    public const AKSI_RESET_PERANGKAT = 'reset_perangkat';

    public const AKSI_RESET_PASSWORD = 'reset_password';

    public const AKSI_UBAH_PENGATURAN = 'ubah_pengaturan';

    public const AKSI_UBAH_INFO_SEKOLAH = 'ubah_info_sekolah';

    public const AKSI_BUAT = 'buat_data';

    public const AKSI_UBAH = 'ubah_data';

    public const AKSI_HAPUS = 'hapus_data';

    public const AKSI_IMPORT = 'import_data';

    public const AKSI_AKTIFKAN_TAHUN = 'aktifkan_tahun_pelajaran';

    public const AKSI_SELESAI_TAHUN = 'tandai_selesai_tahun_pelajaran';

    public const AKSI_PLOTTING_SISWA = 'plotting_siswa';

    public const AKSI_NAIK_KELAS = 'naik_kelas';

    public const AKSI_MUTASI_KELAS = 'mutasi_kelas';

    public const AKSI_BATAL_NAIK_KELAS = 'batal_naik_kelas';

    public const AKSI_SALIN = 'salin_data';

    // ---- Fase 3: presensi & pengajuan ----
    public const AKSI_PRESENSI_MASUK = 'presensi_masuk';

    public const AKSI_PRESENSI_PULANG = 'presensi_pulang';

    public const AKSI_KOREKSI_PRESENSI = 'koreksi_presensi';

    public const AKSI_PUTUSKAN_PRESENSI = 'putuskan_presensi_luar_radius';

    public const AKSI_AJUKAN_IZIN = 'ajukan_izin';

    public const AKSI_PUTUSKAN_IZIN = 'putuskan_izin';

    public const AKSI_BATAL_IZIN = 'batalkan_izin';

    public const AKSI_AJUKAN_LUAR_RADIUS = 'ajukan_luar_radius';

    public const AKSI_PUTUSKAN_LUAR_RADIUS = 'putuskan_luar_radius';

    public const AKSI_TETAPKAN_LOKASI = 'tetapkan_lokasi_pegawai';

    public function catat(
        string $aksi,
        ?User $user = null,
        ?Model $objek = null,
        ?array $dataLama = null,
        ?array $dataBaru = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'aksi' => $aksi,
            'objek_tipe' => $objek ? $objek::class : null,
            'objek_id' => $objek?->getKey(),
            'data_lama' => $dataLama,
            'data_baru' => $dataBaru,
            'ip' => Request::ip(),
            'waktu' => now(),
        ]);
    }
}
