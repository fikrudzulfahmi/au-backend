<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 5.20 / FR-PMN-01 — pengumuman, pengingat, dan teks berjalan.
 *
 * BR-36: tayang HANYA bila `is_active` DAN sekarang berada dalam rentang
 * tanggal (dan jam bila diisi) DAN target tampil cocok. Baris yang kedaluwarsa
 * tidak pernah dihapus — ia hanya berhenti tampil.
 */
class Pengumuman extends Model
{
    use HasFactory;

    public const TIPE_PENGUMUMAN = 'pengumuman';

    public const TIPE_PENGINGAT = 'pengingat';

    public const TIPE_TEKS_BERJALAN = 'teks_berjalan';

    public const PRIORITAS_NORMAL = 'normal';

    public const PRIORITAS_PENTING = 'penting';

    /** Penanda kolom target tampil (BR-36). */
    public const TARGET_APP = 'tampil_app';

    public const TARGET_TV = 'tampil_tv';

    public const TARGET_LANDING = 'tampil_landing';

    protected $table = 'pengumuman';

    protected $fillable = [
        'judul',
        'isi',
        'isi_panjang',
        'tipe',
        'prioritas',
        'tanggal_mulai',
        'tanggal_selesai',
        'jam_mulai',
        'jam_selesai',
        'tampil_app',
        'tampil_tv',
        'tampil_landing',
        'gambar_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'tampil_app' => 'boolean',
            'tampil_tv' => 'boolean',
            'tampil_landing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * BR-36 — pengumuman yang berhak tayang pada saat tertentu.
     * Waktu otoritatif memakai `CarbonImmutable::now()` (zona Asia/Jakarta)
     * sehingga uji dapat membekukan waktu dengan `travelTo()`.
     */
    public function scopeTayang(Builder $query, ?CarbonImmutable $saat = null): Builder
    {
        $saat ??= CarbonImmutable::now();
        $tanggal = $saat->toDateString();
        $jam = $saat->format('H:i:s');

        return $query
            ->where('is_active', true)
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->where(function (Builder $q) use ($tanggal): void {
                $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $tanggal);
            })
            ->where(function (Builder $q) use ($jam): void {
                $q->whereNull('jam_mulai')->orWhereTime('jam_mulai', '<=', $jam);
            })
            ->where(function (Builder $q) use ($jam): void {
                $q->whereNull('jam_selesai')->orWhereTime('jam_selesai', '>=', $jam);
            });
    }

    /** Menyaring berdasarkan penanda target tampil, mis. `tampil_tv`. */
    public function scopeTarget(Builder $query, string $kolom): Builder
    {
        return $query->where($kolom, true);
    }

    public function penting(): bool
    {
        return $this->prioritas === self::PRIORITAS_PENTING;
    }

    public function teksBerjalan(): bool
    {
        return $this->tipe === self::TIPE_TEKS_BERJALAN;
    }
}
