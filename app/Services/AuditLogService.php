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

    public const AKSI_UBAH_PENGATURAN = 'ubah_pengaturan';

    public const AKSI_UBAH_INFO_SEKOLAH = 'ubah_info_sekolah';

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
