<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pengajuan izin / sakit / dinas / cuti (FR-IZN-01..09).
 *
 * BR-25: izin, sakit, dan cuti yang DISETUJUI membebaskan pegawai dari presensi
 * pada hari itu (tombol disembunyikan dan hari itu bukan alpa). Dinas TIDAK
 * membebaskan — guru yang berdinas tetap harus presensi.
 */
class PengajuanIzin extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_izin';

    public const JENIS_IZIN = 'izin';

    public const JENIS_SAKIT = 'sakit';

    public const JENIS_DINAS = 'dinas';

    public const JENIS_CUTI = 'cuti';

    /** @var array<string, string> */
    public const DAFTAR_JENIS = [
        self::JENIS_IZIN => 'Izin',
        self::JENIS_SAKIT => 'Sakit',
        self::JENIS_DINAS => 'Dinas',
        self::JENIS_CUTI => 'Cuti',
    ];

    /** BR-25 — jenis yang membebaskan presensi bila disetujui. */
    public const JENIS_MEMBEBASKAN = [self::JENIS_IZIN, self::JENIS_SAKIT, self::JENIS_CUTI];

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    /** @var array<string, string> */
    public const DAFTAR_STATUS = [
        self::STATUS_MENUNGGU => 'Menunggu',
        self::STATUS_DISETUJUI => 'Disetujui',
        self::STATUS_DITOLAK => 'Ditolak',
        self::STATUS_DIBATALKAN => 'Dibatalkan',
    ];

    protected $fillable = [
        'pegawai_id', 'jenis', 'tanggal_mulai', 'tanggal_selesai', 'alasan', 'lampiran_path',
        'presensi_luar_radius', 'status', 'diputuskan_oleh', 'diputuskan_pada',
        'catatan_penyetuju', 'dibuat_oleh_admin', 'lampiran_dihapus_pada',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'presensi_luar_radius' => 'boolean',
            'dibuat_oleh_admin' => 'boolean',
            'diputuskan_pada' => 'datetime',
            'lampiran_dihapus_pada' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function diputuskanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    /** Baris luar radius yang lahir otomatis dari dinas ini (FR-IZN-02). */
    public function luarRadius(): HasMany
    {
        return $this->hasMany(PengajuanLuarRadius::class, 'pengajuan_izin_id');
    }

    public function scopeMenunggu(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MENUNGGU);
    }

    public function scopeDisetujui(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISETUJUI);
    }

    /** Pengajuan yang mengikat tanggal: masih menunggu atau sudah disetujui (FR-IZN-05). */
    public function scopeMengikat(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_MENUNGGU, self::STATUS_DISETUJUI]);
    }

    public function mencakupTanggal(\DateTimeInterface|string $tanggal): bool
    {
        $t = Carbon::parse($tanggal)->toDateString();

        return $t >= $this->tanggal_mulai->toDateString() && $t <= $this->tanggal_selesai->toDateString();
    }

    /** BR-25 — apakah pengajuan ini membebaskan presensi pada tanggal tersebut. */
    public function membebaskanPresensiPada(\DateTimeInterface|string $tanggal): bool
    {
        return $this->status === self::STATUS_DISETUJUI
            && in_array($this->jenis, self::JENIS_MEMBEBASKAN, true)
            && $this->mencakupTanggal($tanggal);
    }

    public function labelJenis(): string
    {
        return self::DAFTAR_JENIS[$this->jenis] ?? $this->jenis;
    }
}
