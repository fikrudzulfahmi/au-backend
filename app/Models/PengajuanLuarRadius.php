<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan presensi di luar radius (FR-PRS-07 Jalur A, FR-IZN-06).
 * Satu baris = satu tanggal; UQ (pegawai_id, tanggal) membuat BR-17 deterministik.
 */
class PengajuanLuarRadius extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_luar_radius';

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
        'pegawai_id', 'tanggal', 'alasan', 'lampiran_path', 'pengajuan_izin_id',
        'status', 'diputuskan_oleh', 'diputuskan_pada', 'catatan_penyetuju',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'diputuskan_pada' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function pengajuanIzin(): BelongsTo
    {
        return $this->belongsTo(PengajuanIzin::class, 'pengajuan_izin_id');
    }

    public function diputuskanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    public function scopeMenunggu(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MENUNGGU);
    }

    public function sudahDisetujui(): bool
    {
        return $this->status === self::STATUS_DISETUJUI;
    }

    /**
     * BR-17 Jalur A — pengajuan luar radius disetujui milik pegawai pada tanggal tertentu.
     * Inilah yang membuat presensi di luar radius langsung berstatus `disetujui`.
     */
    public static function disetujuiUntuk(int $pegawaiId, string $tanggal): ?self
    {
        return static::query()
            ->where('pegawai_id', $pegawaiId)
            ->where('tanggal', $tanggal)
            ->where('status', self::STATUS_DISETUJUI)
            ->first();
    }
}
