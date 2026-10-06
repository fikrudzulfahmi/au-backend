<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presensi pegawai (FR-PRS-01..13).
 *
 * Satu baris menampung presensi masuk dan pulang hari itu; UQ (pegawai_id, tanggal)
 * menegakkan BR-10 di level database.
 */
class PresensiPegawai extends Model
{
    use HasFactory;

    protected $table = 'presensi_pegawai';

    public const STATUS_HADIR = 'hadir';

    public const STATUS_TERLAMBAT = 'terlambat';

    public const PULANG_NORMAL = 'normal';

    public const PULANG_CEPAT = 'pulang_cepat';

    public const VALID = 'valid';

    public const MENUNGGU = 'menunggu';

    public const DISETUJUI = 'disetujui';

    public const DITOLAK = 'ditolak';

    /** Validasi yang membuat presensi dihitung hadir (BR-18). */
    public const VALIDASI_DIHITUNG = [self::VALID, self::DISETUJUI, self::MENUNGGU];

    protected $fillable = [
        'pegawai_id', 'tanggal', 'semester_id',
        'masuk_waktu', 'masuk_lat', 'masuk_lng', 'masuk_akurasi_m', 'masuk_lokasi_id',
        'masuk_jarak_m', 'masuk_foto_path', 'masuk_status', 'masuk_menit_terlambat',
        'masuk_validasi', 'masuk_alasan_luar_radius', 'masuk_pengajuan_luar_radius_id',
        'pulang_waktu', 'pulang_lat', 'pulang_lng', 'pulang_akurasi_m', 'pulang_lokasi_id',
        'pulang_jarak_m', 'pulang_foto_path', 'pulang_status', 'pulang_menit_cepat',
        'pulang_validasi', 'pulang_alasan_luar_radius', 'pulang_pengajuan_luar_radius_id',
        'diputuskan_oleh', 'diputuskan_pada', 'catatan_penyetuju', 'dikoreksi_admin', 'foto_dihapus_pada',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'masuk_waktu' => 'datetime',
            'pulang_waktu' => 'datetime',
            'diputuskan_pada' => 'datetime',
            'foto_dihapus_pada' => 'datetime',
            'masuk_lat' => 'float',
            'masuk_lng' => 'float',
            'pulang_lat' => 'float',
            'pulang_lng' => 'float',
            'masuk_akurasi_m' => 'integer',
            'pulang_akurasi_m' => 'integer',
            'masuk_jarak_m' => 'integer',
            'pulang_jarak_m' => 'integer',
            'masuk_menit_terlambat' => 'integer',
            'pulang_menit_cepat' => 'integer',
            'dikoreksi_admin' => 'boolean',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function masukLokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPresensi::class, 'masuk_lokasi_id');
    }

    public function pulangLokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPresensi::class, 'pulang_lokasi_id');
    }

    public function diputuskanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    public function scopeTanggal(Builder $query, string $tanggal): Builder
    {
        return $query->where('tanggal', $tanggal);
    }

    public function sudahMasuk(): bool
    {
        return $this->masuk_waktu !== null;
    }

    public function sudahPulang(): bool
    {
        return $this->pulang_waktu !== null;
    }

    /** FR-PRS-05 — belum presensi pulang padahal sudah masuk. */
    public function belumPulang(): bool
    {
        return $this->sudahMasuk() && ! $this->sudahPulang();
    }

    /**
     * BR-18 — presensi `menunggu` sudah tercatat tetapi belum dihitung hadir;
     * `ditolak` dihitung tidak hadir.
     */
    public function dihitungHadir(): bool
    {
        return in_array($this->masuk_validasi, [self::VALID, self::DISETUJUI], true);
    }

    public function menungguPersetujuan(): bool
    {
        return $this->masuk_validasi === self::MENUNGGU
            || $this->pulang_validasi === self::MENUNGGU;
    }
}
